<?php

declare(strict_types=1);

/**
 * Public opt-out landing for the account-less external mail leg (NH-02).
 *
 * GET  renders a confirm page; POST performs the opt-out. The HMAC token is
 * the sole capability (see ExternalMailOptoutService) — no session, no PII in
 * the URL, and both states render the same page chrome (no existence oracle).
 *
 * @copyright Copyright (c) 2026, Lara Raffel, Alexander Mäule and Hauke Klünder
 * @license AGPL-3.0-or-later
 */

namespace OCA\Ticketcheck\Controller;

use OCA\Ticketcheck\Service\ExternalMailOptoutService;
use OCA\Ticketcheck\Service\GuestLayoutParamsProvider;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\L10N\IFactory;
use OCP\IRequest;

class ExternalMailOptoutController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ExternalMailOptoutService $optout,
        private readonly GuestLayoutParamsProvider $layoutParams,
        private readonly IFactory $l10nFactory,
    ) {
        parent::__construct($appName, $request);
    }

    private function page(string $state, int $t, string $e, string $k): TemplateResponse
    {
        $l = $this->l10nFactory->get($this->appName);
        return new TemplateResponse($this->appName, 'optout', [
            'state' => $state, 't' => $t, 'e' => $e, 'k' => $k,
            'formUrl' => '/apps/' . $this->appName . '/extmail/optout',
            // Non-portal guest render: explicit page id + guest nav mode so the
            // shell-context listener registers guest assets instead of
            // misreading the /extmail path as a staff surface.
            'pageId' => 'extmail-optout',
            'navMode' => 'guest',
            'pageTitle' => $l->t('extmail_optout_title'),
        ] + $this->layoutParams->getParams(), 'guest');
    }

    private function params(): array
    {
        $t = (int)$this->request->getParam('t', 0);
        $e = strtolower(trim((string)$this->request->getParam('e', '')));
        $k = strtolower(trim((string)$this->request->getParam('k', '')));
        return [$t, $e, $k];
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 60, period: 300)]
    public function confirm(): TemplateResponse
    {
        [$t, $e, $k] = $this->params();
        return $this->page($this->optout->verify($t, $k, $e) ? 'confirm' : 'invalid', $t, $e, $k);
    }

    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 10, period: 300)]
    public function apply(): TemplateResponse
    {
        [$t, $e, $k] = $this->params();
        if ($this->request->getMethod() !== 'POST' || $t < 1 || !$this->optout->verify($t, $k, $e)) {
            return $this->page('invalid', $t, $e, $k);
        }
        $this->optout->optOut($t, $e);
        return $this->page('done', $t, $e, $k);
    }
}
