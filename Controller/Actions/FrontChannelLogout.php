<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\RawFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;
use M2Oidc\OAuth\Model\Service\SessionDestructionService;

/**
 * Front-channel OIDC logout endpoint.
 *
 * Route: GET /m2oidc/actions/frontchannellogout?sid=<sid>
 *
 * Some IdPs (Microsoft Entra, certain Keycloak configurations) perform
 * front-channel logout by embedding an <iframe> pointing to each SP's
 * logout URL in the user's browser after the IdP session is terminated.
 *
 * This controller:
 *  1. Validates and sanitises the `sid` query parameter.
 *  2. Looks up the matching PHP session(s) via OidcSessionRegistry.
 *  3. Destroys each session using the shared SessionDestructionService (C-02 pattern).
 *  4. Returns a 1×1 transparent GIF — required by the OIDC spec so the
 *     iframe receives a valid HTTP 200 response.
 *
 * Registration with the IdP:
 *  - Front-Channel Logout URI: https://<store>/m2oidc/actions/frontchannellogout
 *  - No client credentials, JWT, or signed token is required from the IdP.
 *  - The `sid` parameter must be sent by the IdP as a query parameter.
 *
 * Security:
 *  - Rate-limited via OidcRateLimiter (same thresholds as BackChannelLogout).
 *  - `sid` is validated against an alphanumeric-plus-dash pattern before lookup.
 *  - Missing or invalid `sid` returns HTTP 400 with an empty GIF body.
 */
class FrontChannelLogout extends BaseAction implements HttpGetActionInterface
{
    /**
     * 1×1 transparent GIF (binary, 43 bytes).
     * Returned so the IdP iframe receives a valid HTTP 200 image response.
     */
    private const string TRANSPARENT_GIF =
        "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff" .
        "\x00\x00\x00\x21\xf9\x04\x00\x00\x00\x00\x00\x2c\x00\x00\x00\x00" .
        "\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    /** @var RawFactory */
    private readonly RawFactory $rawFactory;

    /** @var OidcSessionRegistry */
    private readonly OidcSessionRegistry $sessionRegistry;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /** @var SessionDestructionService */
    private readonly SessionDestructionService $sessionDestructionService;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param OAuthUtility                          $oauthUtility
     * @param RawFactory                            $rawFactory
     * @param OidcSessionRegistry                   $sessionRegistry
     * @param OidcRateLimiter                       $rateLimiter
     * @param SessionDestructionService             $sessionDestructionService
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        OAuthUtility $oauthUtility,
        RawFactory $rawFactory,
        OidcSessionRegistry $sessionRegistry,
        OidcRateLimiter $rateLimiter,
        SessionDestructionService $sessionDestructionService
    ) {
        $this->rawFactory                = $rawFactory;
        $this->sessionRegistry           = $sessionRegistry;
        $this->rateLimiter               = $rateLimiter;
        $this->sessionDestructionService = $sessionDestructionService;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Handle the front-channel logout iframe request.
     */
    #[\Override]
    public function execute(): ResultInterface
    {
        $request  = $this->getRequest();
        $clientIp = ($request instanceof \Magento\Framework\App\Request\Http)
            ? (string) $request->getClientIp()
            : '';

        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->oauthUtility->customlog(
                'FrontChannelLogout: Rate limit exceeded for IP: ' . $clientIp
            );
            return $this->gifResponse(429);
        }

        $sid = trim((string) $this->getRequest()->getParam('sid', ''));

        if ($sid === '') {
            $this->oauthUtility->customlog('FrontChannelLogout: Missing sid parameter — ignored.');
            return $this->gifResponse(400);
        }

        // Validate sid format before any cache lookup (prevents path traversal)
        if (!preg_match('/^[a-zA-Z0-9,_-]+$/', $sid)) {
            $this->oauthUtility->customlog(
                'FrontChannelLogout: Invalid sid format — rejected.'
            );
            return $this->gifResponse(400);
        }

        // Resolve sessions by sid only — sub is not present in front-channel logout requests
        $entries = $this->sessionRegistry->resolveBySid($sid);

        if ($entries === null) {
            $this->oauthUtility->customlogContext('oidc.frontchannel_logout', [
                'sid'    => $sid,
                'result' => 'session_not_found',
            ]);
            return $this->gifResponse(200);
        }

        foreach ($entries as $entry) {
            $phpSessionId = (string) ($entry['php_session_id'] ?? '');
            if ($phpSessionId !== '') {
                $this->sessionDestructionService->destroySession($phpSessionId);
                $this->sessionDestructionService->clearOnlineStatus($entry);
            }
        }

        // Revoke both primary (sub+sid) and secondary (sid-only) index entries
        $sub = (string) ($entries[0]['sub'] ?? '');
        $this->sessionRegistry->revoke($sub, $sid);

        $this->oauthUtility->customlogContext('oidc.frontchannel_logout', [
            'sid'    => $sid,
            'result' => 'session_destroyed',
            'count'  => count($entries),
        ]);

        return $this->gifResponse(200);
    }

    /**
     * Return a 1×1 transparent GIF response.
     *
     * @param int $statusCode HTTP status code
     */
    private function gifResponse(int $statusCode): ResultInterface
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setHeader('Content-Type', 'image/gif', true);
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $result->setHeader('Pragma', 'no-cache', true);
        $result->setContents(self::TRANSPARENT_GIF);
        return $result;
    }
}
