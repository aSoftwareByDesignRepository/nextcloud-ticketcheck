<?php
/** Synced from _shared/agent-auth — do not edit app-side. */

declare(strict_types=1);

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Agent\AgentAccessGate;
use OCA\Ticketcheck\Agent\AgentContext;
use OCA\Ticketcheck\Agent\AgentException;
use OCA\Ticketcheck\Agent\AgentManifest;
use OCA\Ticketcheck\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves the live agent contract at GET /apps/ticketcheck/agent/v1/manifest.
 *
 * Auth (pinned): any valid X-TicketCheck-Agent token (any scope — the
 * middleware resolves + entitles it before dispatch) OR a session-authed
 * user with app access. Never public.
 *
 * The MCP adapter fetches this at startup and treats the served copy as
 * authoritative — repo-bundled manifests are for CI parity only.
 */
class AgentManifestController extends Controller
{
	public function __construct(
		IRequest $request,
		private readonly AgentContext $agentContext,
		private readonly AgentManifest $manifest,
		private readonly AgentAccessGate $accessGate,
		private readonly IUserSession $userSession,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// PublicPage: agent tokens never establish a session (NN-10), so the
	// framework login gate would kill every agent request before our
	// middleware runs. Auth is manual below — armed context OR session
	// user with app access; anonymous still 401s.
	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(): JSONResponse
	{
		if (!$this->agentContext->isArmed()) {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new AgentException('agent_token_invalid', 'Authentication required.', 401);
			}
			if (!$this->accessGate->canUseApp($user->getUID())) {
				throw new AgentException('agent_scope_denied', 'No app access.', 403);
			}
		}

		$version = $this->appManager->getAppVersion(Application::APP_ID, false);
		return new JSONResponse($this->manifest->servedEnvelope((string)$version));
	}
}
