<?php

declare(strict_types=1);

/**
 * Application class for the helpdesk app
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\Ticketcheck\Service\UpgradeBackupService;
use OCA\Ticketcheck\Repair\BackupBeforeUpdate;
use OCA\Ticketcheck\Repair\EnsureTicketcheckSchema;
use OCA\Ticketcheck\Repair\CleanupDanglingMergeShells;
use OCA\Ticketcheck\Repair\UninstallDropTables;
use OCA\Ticketcheck\Service\GuestAccessAllowlist;
use OCA\Ticketcheck\Service\PermissionService;
use OCA\Ticketcheck\Support\NavGate;
use OCP\INavigationManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Class Application
 *
 * @package OCA\Ticketcheck
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'ticketcheck';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    /**
     * Register the app
     */
    public function register(IRegistrationContext $context): void
    {
        // Register security middleware.
        // afterException iterates middlewares in REVERSE registration order, so
        // the catch-all API envelope is registered FIRST = consulted LAST (every
        // typed middleware still handles its own exceptions first).
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\ApiExceptionMiddleware::class);
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\GuestSecurityMiddleware::class);
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\AppAccessMiddleware::class);
        // Companion TKC2 seat gate (Basic auth only; never gates browser session)
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\ClientLicenseMiddleware::class);
        // Register CSP middleware to enforce policies and inject nonces (SCOPED to helpdesk routes only)
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\CSPMiddleware::class);

        // Session CSRF guard: re-asserts requesttoken on session-authenticated
        // mutations even when OCS-APIRequest or #[NoCSRFRequired] would skip
        // Nextcloud's own check. Basic-auth (companion) and anonymous requests exempt.
        $context->registerService(\OCA\Ticketcheck\Service\CsrfTokenValidator::class, function ($c): \OCA\Ticketcheck\Service\CsrfTokenValidator {
            return new \OCA\Ticketcheck\Service\CsrfTokenValidator(
                $c->get(\OC\Security\CSRF\CsrfTokenManager::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Middleware\SessionCsrfMiddleware::class, function ($c): \OCA\Ticketcheck\Middleware\SessionCsrfMiddleware {
            return new \OCA\Ticketcheck\Middleware\SessionCsrfMiddleware(
                $c->get(\OCP\IRequest::class),
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCA\Ticketcheck\Service\CsrfTokenValidator::class),
            );
        });
        $context->registerMiddleware(\OCA\Ticketcheck\Middleware\SessionCsrfMiddleware::class);

        $context->registerNotifierService(\OCA\Ticketcheck\Notification\Notifier::class);

        $context->registerService(\OCA\Ticketcheck\Service\LocaleFormatService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\LocaleFormatService(
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCP\IDateTimeFormatter::class),
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\IDateTimeZone::class),
                $c->get(\OCP\IConfig::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\FrontEndAssetService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\FrontEndAssetService(
                $c->get(\OCP\IRequest::class),
            );
        });

        $context->registerService(EnsureTicketcheckSchema::class, function ($c): EnsureTicketcheckSchema {
            return new EnsureTicketcheckSchema(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\IConfig::class),
            );
        });

        $context->registerService(CleanupDanglingMergeShells::class, function ($c): CleanupDanglingMergeShells {
            return new CleanupDanglingMergeShells(
                $c->get('TicketService'),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        $context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->get(\OCP\IDBConnection::class),
				$c->get(\OCP\IConfig::class),
				$c->get(IRootFolder::class),
				$c->get(IAppManager::class),
				$c->get(ILockingProvider::class),
				$c->get(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->get(UpgradeBackupService::class),
			);
		});


        // Register event listeners for guest user isolation
        $context->registerEventListener(
            \OCP\User\Events\UserDeletedEvent::class,
            \OCA\Ticketcheck\Listener\UserDeletedListener::class
        );

        $context->registerEventListener(
            \OCP\User\Events\PostLoginEvent::class,
            \OCA\Ticketcheck\Listener\GuestUserLoginListener::class
        );

        $context->registerEventListener(
            \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
            \OCA\Ticketcheck\Listener\EnrichTemplateShellContext::class
        );

        $context->registerEventListener(
            \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
            \OCA\Ticketcheck\Listener\GuestUserBeforeTemplateRenderedListener::class
        );

        // CRITICAL: Register GLOBAL request listener to block ALL forbidden app access
        // This is the first line of defense - intercepts requests before any app routing
        // Using BeforeTemplateRenderedEvent as a secondary check (boot() method is primary)
        $context->registerEventListener(
            \OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
            \OCA\Ticketcheck\Listener\GlobalGuestRequestListener::class
        );

        // Dynamic ticket categories (registry + label resolution). The
        // optional InboundMailConfigService dep is resolved lazily here to
        // avoid a ctor-level cycle (inbound classify uses this service too).
        $context->registerService(\OCA\Ticketcheck\Db\TicketCategoryMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketCategoryMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\TicketCategoryService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketCategoryService(
                $c->get(\OCA\Ticketcheck\Db\TicketCategoryMapper::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Db\TicketResourceMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketResourceMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Db\TicketResourceMapMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketResourceMapMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\TicketResourceService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketResourceService(
                $c->get(\OCA\Ticketcheck\Db\TicketResourceMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketResourceMapMapper::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        // Register services
        $context->registerService('TicketService', function ($c) {
            return new \OCA\Ticketcheck\Service\TicketService(
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get(\OCA\Ticketcheck\Db\CommentMapper::class),
                $c->get(\OCA\Ticketcheck\Db\AttachmentMapper::class),
                $c->get(\OCA\Ticketcheck\Service\AttachmentCleanupService::class),
                $c->get(\OCA\Ticketcheck\Service\AttachmentUploadService::class),
                $c->get(\OCA\Ticketcheck\Service\TicketRelationService::class),
                $c->get(\OCA\Ticketcheck\Service\UserValidationService::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\OCA\Ticketcheck\Service\CompanionNotificationService::class),
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Ticketcheck\Service\TicketCategoryService::class),
                $c->get(\OCA\Ticketcheck\Service\TicketResourceService::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\TicketService::class, function ($c) {
            return $c->get('TicketService');
        });

        $context->registerService('PermissionService', function ($c) {
            return new \OCA\Ticketcheck\Service\PermissionService(
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCA\Ticketcheck\Db\GuestProjectAccessMapper::class),
                $c->get(\OCP\IDBConnection::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\PermissionService::class, function ($c) {
            return $c->get('PermissionService');
        });

        $context->registerService('EmailService', function ($c) {
            return new \OCA\Ticketcheck\Service\EmailService(
                $c->get(\OCP\Mail\IMailer::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                self::APP_ID,
                $c->get(\OCA\Ticketcheck\Db\GuestProjectAccessMapper::class),
                $c->get('ProjectService'),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCA\Ticketcheck\Service\EmailPreferencesService::class),
                $c->get(\OCA\Ticketcheck\Service\EmailHeaderSanitizer::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCA\Ticketcheck\Db\TicketWatcherMapper::class),
                $c->get(\OCA\Ticketcheck\Service\InboundSmtpSendService::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\ActivityEventService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\ActivityEventService(
                $c->get(\OCP\Activity\IManager::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCA\Ticketcheck\Db\TicketWatcherMapper::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\App\IAppManager::class)
            );
        });

        // Register standalone ProjectService
        $context->registerService('ProjectService', function ($c) {
            return new \OCA\Ticketcheck\Service\ProjectService(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\TicketWorkflowLock::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketWorkflowLock(
                $c->get(\OCP\Lock\ILockingProvider::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\TicketRelationService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketRelationService(
                $c->get(\OCA\Ticketcheck\Db\TicketLinkMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketWatcherMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketSurveyMapper::class),
                $c->get(\Psr\Log\LoggerInterface::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\MergeService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\MergeService(
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get(\OCA\Ticketcheck\Db\CommentMapper::class),
                $c->get(\OCA\Ticketcheck\Db\AttachmentMapper::class),
                $c->get('PermissionService'),
                $c->get(\OCA\Ticketcheck\Service\TicketRelationService::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\OCA\Ticketcheck\Service\TicketResourceService::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Facade\CrmHelpdeskReadFacade::class, function ($c) {
            return new \OCA\Ticketcheck\Facade\CrmHelpdeskReadFacade(
                $c->get(\OCA\Ticketcheck\Db\HelpdeskCustomerMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get(\OCP\IURLGenerator::class),
            );
        });
        // Companion CRM-app write-link — server-side only.
        $context->registerService(\OCA\Ticketcheck\Public\CrmHelpdeskWriteFacade::class, function ($c) {
            return new \OCA\Ticketcheck\Public\CrmHelpdeskWriteFacade(
                $c->get('ProjectService'),
                $c->get(\OCA\Ticketcheck\Db\HelpdeskCustomerMapper::class),
                $c->get(\OCP\IGroupManager::class),
            );
        });
        // FC-WCHK-EXPORT — one-way escalation export for whistlecheck;
        // explicit registration keeps Server::get(FQCN) deterministic.
        $context->registerService(\OCA\Ticketcheck\Public\EscalationTicketWriteFacade::class, function ($c) {
            return new \OCA\Ticketcheck\Public\EscalationTicketWriteFacade(
                $c->get(\OCA\Ticketcheck\Service\TicketService::class),
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCA\Ticketcheck\Service\IdempotencyService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCA\Ticketcheck\Service\TicketCategoryService::class),
                $c->get(\OCA\Ticketcheck\Service\TicketResourceService::class),
            );
        });

        // Register mappers
        $context->registerService(\OCA\Ticketcheck\Db\TicketMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\CommentMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\CommentMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\AttachmentMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\AttachmentMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\TicketLinkMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketLinkMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\TicketWatcherMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketWatcherMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\TicketLinkService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketLinkService(
                $c->get(\OCA\Ticketcheck\Db\TicketLinkMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('PermissionService'),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\TicketWatcherService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\TicketWatcherService(
                $c->get(\OCA\Ticketcheck\Db\TicketWatcherMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('PermissionService'),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\SplitService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\SplitService(
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('TicketService'),
                $c->get('PermissionService'),
                $c->get(\OCA\Ticketcheck\Service\TicketLinkService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\OCA\Ticketcheck\Service\TicketResourceService::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\TicketSurveyMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\TicketSurveyMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\SurveyService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\SurveyService(
                $c->get(\OCA\Ticketcheck\Db\TicketSurveyMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class)
            );
        });

        // Inbound mail (IMAP + shared webhook/IMAP processor)
        $context->registerService(\OCA\Ticketcheck\Service\InboundMailConfigService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundMailConfigService(
                $c->get(\OCP\IAppConfig::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\OCP\Security\ICrypto::class),
                $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundMailAuthService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundMailAuthService(
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundMailOAuthService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundMailOAuthService(
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
                $c->get(\OCP\ICacheFactory::class),
                $c->get(\OCP\Security\ISecureRandom::class),
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\OCP\L10N\IFactory::class)->get(self::APP_ID)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundMailDetectService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundMailDetectService(
                $c->get(\OCA\Ticketcheck\Service\InboundMailAuthService::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\ReplyTokenService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\ReplyTokenService(
                $c->get(\OCP\IConfig::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundAttachmentIngestService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundAttachmentIngestService(
                $c->get(\OCA\Ticketcheck\Service\AttachmentUploadService::class),
                $c->get('TicketService'),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundSmtpSendService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundSmtpSendService(
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
                $c->get(\OCA\Ticketcheck\Service\InboundMailAuthService::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundMailSyncService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundMailSyncService(
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
                $c->get(\OCA\Ticketcheck\Service\InboundMailAuthService::class),
                $c->get(\OCA\Ticketcheck\Service\InboundEmailService::class),
                $c->get(\OCA\Ticketcheck\Service\InboundAttachmentIngestService::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\InboundEmailService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\InboundEmailService(
                $c->get('TicketService'),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCA\Ticketcheck\Service\InboundMailConfigService::class),
                $c->get(\OCA\Ticketcheck\Db\InboundMessageMapper::class),
                $c->get(\OCA\Ticketcheck\Service\ReplyTokenService::class),
                $c->get('EmailService'),
                $c->get(\OCA\Ticketcheck\Db\HelpdeskCustomerMapper::class),
                $c->get(\OCA\Ticketcheck\Db\HelpdeskProjectMapper::class),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCA\Ticketcheck\Service\TicketCategoryService::class),
                $c->get(\OCA\Ticketcheck\Service\TicketResourceService::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\BackgroundJob\InboundMailSyncJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\InboundMailSyncJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Service\InboundMailSyncService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\Lock\ILockingProvider::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\BackgroundJob\InboundMailRetentionJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\InboundMailRetentionJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Db\InboundMessageMapper::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\Lock\ILockingProvider::class)
            );
        });

        // Utility services
        $context->registerService(\OCA\Ticketcheck\Service\AttachmentCleanupService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\AttachmentCleanupService(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCA\Ticketcheck\Db\AttachmentMapper::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\IConfig::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\UserValidationService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\UserValidationService(
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IConfig::class),
                $c->get('PermissionService'),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\GuestPasswordPolicyService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\GuestPasswordPolicyService(
                $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\RoleAccessSyncService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\RoleAccessSyncService(
                $c->get(\OCP\IDBConnection::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // CSP nonce adapter + service (private OC nonce manager wrapped once)
        $context->registerService(\OCA\Ticketcheck\Security\CspNonceProvider::class, function ($c) {
            return new \OCA\Ticketcheck\Security\CspNonceProvider(
                $c->get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class),
            );
        });
        $context->registerService(\OCA\Ticketcheck\Service\CSPService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\CSPService(
                $c->get(\OCA\Ticketcheck\Security\CspNonceProvider::class),
            );
        });

        // Register background jobs
        $context->registerService(\OCA\Ticketcheck\BackgroundJob\NotificationJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\NotificationJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('EmailService'),
                $c->get(\OCP\IConfig::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCA\Ticketcheck\Service\EmailPreferencesService::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
            );
        });

		$context->registerService(\OCA\Ticketcheck\BackgroundJob\SLAMonitorJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\SLAMonitorJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get(\OCA\Ticketcheck\Db\CommentMapper::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get('EmailService'),
                $c->get(\OCA\Ticketcheck\Service\EmailPreferencesService::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IConfig::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
                $c->get(\OCA\Ticketcheck\Service\TicketWorkflowLock::class),
                $c->get(\OCA\Ticketcheck\Service\AttachmentCleanupService::class),
                $c->get('TicketService'),
                $c->get(\OCA\Ticketcheck\Service\KnowledgeBaseService::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\BackgroundJob\WeeklyDigestJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\WeeklyDigestJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('EmailService'),
                $c->get(\OCP\IConfig::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCA\Ticketcheck\Service\EmailPreferencesService::class),
                $c->get(\OCP\IUserManager::class),
                $c->get(\OCP\IGroupManager::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\EscalationRuleMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\EscalationRuleMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\EscalationService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\EscalationService(
                $c->get(\OCA\Ticketcheck\Db\EscalationRuleMapper::class),
                $c->get(\OCA\Ticketcheck\Db\TicketMapper::class),
                $c->get('TicketService'),
                $c->get(\OCP\IUserManager::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\BackgroundJob\EscalationJob::class, function ($c) {
            return new \OCA\Ticketcheck\BackgroundJob\EscalationJob(
                $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
                $c->get(\OCA\Ticketcheck\Service\EscalationService::class),
                $c->get(\Psr\Log\LoggerInterface::class),
                $c->get(\OCP\Lock\ILockingProvider::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\KBArticleMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\KBArticleMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\KBCommentMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\KBCommentMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\KBCategoryMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\KBCategoryMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\KnowledgeBaseService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\KnowledgeBaseService(
                $c->get(\OCA\Ticketcheck\Db\KBArticleMapper::class),
                $c->get(\OCA\Ticketcheck\Db\KBCommentMapper::class),
                $c->get(\OCA\Ticketcheck\Db\KBHelpfulVoteMapper::class),
                $c->get(\OCA\Ticketcheck\Service\CategoryService::class),
                $c->get(\OCA\Ticketcheck\Service\PermissionService::class),
                $c->get(\OCA\Ticketcheck\Service\SafeFilenameService::class),
                $c->get(\OCA\Ticketcheck\Service\HtmlSanitizerService::class),
                $c->get(\OCP\Files\AppData\IAppDataFactory::class),
                $c->get(\Psr\Log\LoggerInterface::class)
            );
        });

        // EmailWhitelistMapper removed in standalone version

        $context->registerService(\OCA\Ticketcheck\Db\AssignmentMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\AssignmentMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Db\GuestProjectAccessMapper::class, function ($c) {
            return new \OCA\Ticketcheck\Db\GuestProjectAccessMapper(
                $c->get(\OCP\IDBConnection::class)
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\HtmlSanitizerService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\HtmlSanitizerService();
        });

        $context->registerService(\OCA\Ticketcheck\Service\SafeFilenameService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\SafeFilenameService();
        });

        $context->registerService(\OCA\Ticketcheck\Service\AttachmentDeliveryService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\AttachmentDeliveryService(
                $c->get(\OCP\IConfig::class),
                $c->get(\OCA\Ticketcheck\Service\SafeFilenameService::class),
            );
        });

        $context->registerService(\OCA\Ticketcheck\Service\EmailHeaderSanitizer::class, function ($c) {
            return new \OCA\Ticketcheck\Service\EmailHeaderSanitizer();
        });

        // Guest layout: OCP-only params for layout.guest.php (no \OC in template)
        $context->registerService(\OCA\Ticketcheck\Service\GuestLayoutParamsProvider::class, function ($c) {
            $themeColor = '#0082c9';
            try {
                $theming = $c->get(\OCA\Theming\ThemingDefaults::class);
                if ($theming instanceof \OCA\Theming\ThemingDefaults) {
                    $themeColor = $theming->getColorPrimary();
                }
            } catch (\Throwable $e) {
                // Theming app not available
            }
            $enabledThemes = [];
            try {
                $themesService = $c->get(\OCA\Theming\Service\ThemesService::class);
                if ($themesService instanceof \OCA\Theming\Service\ThemesService) {
                    $enabledThemes = $themesService->getEnabledThemes();
                }
            } catch (\Throwable $e) {
                // Theming app not available
            }
            return new \OCA\Ticketcheck\Service\GuestLayoutParamsProvider(
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\App\IAppManager::class),
                $c->get(\OCP\L10N\IFactory::class),
                $c->get(\OCP\IConfig::class),
                $themeColor,
                $enabledThemes
            );
        });

        // Single render path for the guest portal (GUEST-01).
        $context->registerService(\OCA\Ticketcheck\Service\GuestPortalPageService::class, function ($c) {
            return new \OCA\Ticketcheck\Service\GuestPortalPageService(
                $c->get(\OCA\Ticketcheck\Service\GuestLayoutParamsProvider::class),
                $c->get(\OCA\Ticketcheck\Service\NavigationContextService::class),
                $c->get(\OCA\Ticketcheck\Service\PermissionService::class),
                $c->get(\OCP\IURLGenerator::class),
                $c->get(\OCP\IUserSession::class),
                $c->get(\OCP\L10N\IFactory::class),
            );
        });

        // AssignmentService removed for standalone operation
        $context->registerDashboardWidget(\OCA\Ticketcheck\Dashboard\TicketOverviewWidget::class);
    }

    /**
     * Boot the app
     */
    public function boot(IBootContext $context): void
    {
        $app = $context->getAppContainer();
        NavGate::register(
            $app,
            'ticketcheck.page.index',
            'app.svg',
            'ticketcheck',
            11,
            static fn(string $uid): bool => $app->get(PermissionService::class)->canUserAccessAppByUid($uid),
        );

        // HTML assets: EnrichTemplateShellContext → FrontEndAssetService; exempt surfaces in
        // layout.guest.php, access-denied.php, desklet, NC personal settings (ARCH-03).

        // CRITICAL: Intercept ALL requests for guest users IMMEDIATELY
        $this->interceptGuestRequests();

        // Set up security restrictions for guest users
        $this->setupGuestUserSecurity();

        // Override navigation for guest users
        $this->overrideNavigationForGuests();
    }

    /**
     * Intercept guest user requests at the earliest possible point.
     * Deny-by-default: anything not on GuestAccessAllowlist redirects to the portal.
     * (A denylist of known apps previously left /settings/admin and unknown routes open.)
     */
    private function interceptGuestRequests(): void
    {
        $container = $this->getContainer();
        $userSession = $container->get(\OCP\IUserSession::class);
        $groupManager = $container->get(\OCP\IGroupManager::class);
        $request = $container->get(\OCP\IRequest::class);
        $urlGenerator = $container->get(\OCP\IURLGenerator::class);

        $user = $userSession->getUser();
        if (!$user) {
            return;
        }

        if (!$groupManager->isInGroup($user->getUID(), 'helpdesk_customers')) {
            return;
        }

        $path = GuestAccessAllowlist::normalizePath($request->getPathInfo() ?? '');
        if (GuestAccessAllowlist::isPathAllowed($path)) {
            return;
        }

        $logger = $container->get(\Psr\Log\LoggerInterface::class);
        $logger->warning('Guest blocked at boot (path not on allowlist)', [
            'user_id' => $user->getUID(),
            'path' => $path,
            'ip' => $request->getRemoteAddress(),
        ]);
        $portalUrl = $urlGenerator->linkToRoute('ticketcheck.customerPortal.index');
        header('Location: ' . $portalUrl, true, 302);
        exit();
    }

    /**
     * Configure bulletproof security for guest users
     * Ensures guests can ONLY access helpdesk portal, nothing else
     * 
     * NOTE: We do NOT restrict app 'enabled' settings here as that would lock out
     * regular users who aren't in helpdesk groups. Guest isolation is handled by:
     * 1. interceptGuestRequests() - blocks at boot level
     * 2. GuestSecurityMiddleware - blocks at middleware level  
     * 3. Zero quota enforcement - guests can't use file storage
     */
    private function setupGuestUserSecurity(): void
    {
        $container = $this->getContainer();
        $groupManager = $container->get(\OCP\IGroupManager::class);

        // Ensure helpdesk_customers group exists
        if (!$groupManager->get('helpdesk_customers')) {
            $groupManager->createGroup('helpdesk_customers');
        }

        // Ensure helpdesk_agents group exists
        if (!$groupManager->get('helpdesk_agents')) {
            $groupManager->createGroup('helpdesk_agents');
        }

        // Ensure helpdesk_admins group exists
        if (!$groupManager->get('helpdesk_admins')) {
            $groupManager->createGroup('helpdesk_admins');
        }

        // Guest isolation is enforced by interceptGuestRequests(), GuestSecurityMiddleware,
        // and PermissionService::canAccessApp() — do not rewrite app config from boot.
    }

    /**
     * Override navigation menu for guest users
     * Hide all apps except helpdesk portal
     */
    private function overrideNavigationForGuests(): void
    {
        $container = $this->getContainer();
        $userSession = $container->get(\OCP\IUserSession::class);
        $groupManager = $container->get(\OCP\IGroupManager::class);

        $user = $userSession->getUser();
        if (!$user) {
            return;
        }

        // Check if user is a guest
        if (!$groupManager->isInGroup($user->getUID(), 'helpdesk_customers')) {
            return;
        }

        // Clear all navigation entries for guests
        $navigationManager = $container->get(\OCP\INavigationManager::class);

        // Remove all default navigation entries
        // Guests should not see Files, Photos, Calendar, etc.
        $navigationManager->clear();
    }
}
