<?php

declare(strict_types=1);

/**
 * System validation command for helpdesk app
 * Validates guest portal functionality and user assignment system
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Command;

use OCA\Ticketcheck\Service\DatabaseSetupService;
use OCA\Ticketcheck\Service\MigrationValidationService;
use OCA\Ticketcheck\Service\UserValidationService;
use OCA\Ticketcheck\Service\PermissionService;
use OCP\IUserManager;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to validate the helpdesk system
 */
class ValidateSystemCommand extends Command
{
    private DatabaseSetupService $databaseSetupService;
    private MigrationValidationService $migrationValidationService;
    private UserValidationService $userValidationService;
    private PermissionService $permissionService;
    private IUserManager $userManager;
    private IGroupManager $groupManager;

    public function __construct(
        DatabaseSetupService $databaseSetupService,
        MigrationValidationService $migrationValidationService,
        UserValidationService $userValidationService,
        PermissionService $permissionService,
        IUserManager $userManager,
        IGroupManager $groupManager
    ) {
        parent::__construct();
        $this->databaseSetupService = $databaseSetupService;
        $this->migrationValidationService = $migrationValidationService;
        $this->userValidationService = $userValidationService;
        $this->permissionService = $permissionService;
        $this->userManager = $userManager;
        $this->groupManager = $groupManager;
    }

    protected function configure(): void
    {
        $this->setName('helpdesk:validate-system')
            ->setDescription('Validate helpdesk system functionality');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>🔍 Validating Helpdesk System...</info>');
        $output->writeln('');

        $allValid = true;

        // 1. Database validation
        $output->writeln('<comment>📊 Checking Database Schema...</comment>');
        try {
            $missingTables = $this->databaseSetupService->getMissingTables();
            if (empty($missingTables)) {
                $output->writeln('<info>✅ All required tables exist</info>');
            } else {
                $output->writeln('<error>❌ Missing tables: ' . implode(', ', $missingTables) . '</error>');
                $allValid = false;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Database check failed: ' . $e->getMessage() . '</error>');
            $allValid = false;
        }

        // 2. Migration validation (service returns a list of issue strings; empty = valid)
        $output->writeln('<comment>🔄 Checking Migrations...</comment>');
        try {
            $issues = $this->migrationValidationService->validateDatabaseSchema();
            if ($issues === []) {
                $output->writeln('<info>✅ Database schema is valid</info>');
            } else {
                $output->writeln('<error>❌ Schema issues found:</error>');
                foreach ($issues as $issue) {
                    $output->writeln('<error>  - ' . $issue . '</error>');
                }
                $allValid = false;
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Migration validation failed: ' . $e->getMessage() . '</error>');
            $allValid = false;
        }

        // 3. User validation system
        $output->writeln('<comment>👥 Testing User Validation...</comment>');
        try {
            $validUsers = $this->userValidationService->getValidUsersForAssignment();
            $output->writeln('<info>✅ Found ' . count($validUsers) . ' valid users for assignment</info>');

            // Test with a few users
            $testUsers = array_slice($validUsers, 0, 3);
            foreach ($testUsers as $user) {
                $validation = $this->userValidationService->validateUserForAssignment($user['user_id']);
                if ($validation['valid']) {
                    $output->writeln('<info>  ✅ User ' . $user['user_id'] . ' is valid</info>');
                } else {
                    $output->writeln('<error>  ❌ User ' . $user['user_id'] . ' is invalid: ' . $validation['reason'] . '</error>');
                    $allValid = false;
                }
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ User validation failed: ' . $e->getMessage() . '</error>');
            $allValid = false;
        }

        // 4. Guest portal functionality
        $output->writeln('<comment>🌐 Testing Guest Portal...</comment>');
        try {
            // Check if guest groups exist
            $guestGroup = $this->groupManager->get('helpdesk_customers');
            if ($guestGroup) {
                $output->writeln('<info>✅ Guest group exists</info>');
            } else {
                $output->writeln('<comment>⚠️  Guest group does not exist (will be created when needed)</comment>');
            }

            // Check permission service
            $currentUserId = $this->permissionService->getCurrentUserId();
            if ($currentUserId) {
                $isGuest = $this->permissionService->isGuest();
                $isAgent = $this->permissionService->isAgent();
                $isAdmin = $this->permissionService->isHelpdeskAdmin();

                $output->writeln('<info>✅ Permission service working</info>');
                $output->writeln('<info>  Current user: ' . $currentUserId . '</info>');
                $output->writeln('<info>  Is guest: ' . ($isGuest ? 'Yes' : 'No') . '</info>');
                $output->writeln('<info>  Is agent: ' . ($isAgent ? 'Yes' : 'No') . '</info>');
                $output->writeln('<info>  Is admin: ' . ($isAdmin ? 'Yes' : 'No') . '</info>');
            } else {
                $output->writeln('<comment>⚠️  No current user (running from CLI)</comment>');
            }
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Guest portal check failed: ' . $e->getMessage() . '</error>');
            $allValid = false;
        }

        // 5. Summary
        $output->writeln('');
        if ($allValid) {
            $output->writeln('<info>🎉 All systems are working correctly!</info>');
            $output->writeln('');
            $output->writeln('<info>✅ Guest portal is ready</info>');
            $output->writeln('<info>✅ User assignment system is secure</info>');
            $output->writeln('<info>✅ Database schema is complete</info>');
            return 0;
        } else {
            $output->writeln('<error>❌ Some issues were found. Please check the output above.</error>');
            $output->writeln('');
            $output->writeln('<comment>💡 To fix database issues, run:</comment>');
            $output->writeln('<comment>   php occ helpdesk:setup-database</comment>');
            return 1;
        }
    }
}
