<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use M2Oidc\OAuth\Helper\JwtVerifier;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;
use M2Oidc\OAuth\Model\Service\SessionDestructionService;

/**
 * Back-channel (server-to-server) OIDC logout endpoint (FEAT-02).
 *
 * Route: POST /m2oidc/actions/backchannellogout
 *
 * The IdP calls this endpoint when a user's SSO session is terminated.
 * It validates the logout token (RFC 9068 / OIDC Back-Channel Logout 1.0),
 * extracts the `sub` and optional `sid` claims, resolves the matching
 * Magento session from the OidcSessionRegistry, and destroys it.
 *
 * Implements CsrfAwareActionInterface to opt out of Magento's form-key
 * CSRF check — back-channel requests come from the IdP server, not from
 * a browser form.
 *
 * Security:
 *  - The logout token is a signed JWT; signature is verified via JWKS.
 *  - Token must contain `sub` or `sid` (per OIDC Back-Channel Logout spec §2.4).
 *  - `events` claim must contain `http://schemas.openid.net/event/backchannel-logout`.
 *  - Returns HTTP 200 on success, 400 on validation failure, 501 when the
 *    provider is not found (IdP misconfiguration).
 */
class BackChannelLogout extends BaseAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** OIDC Back-Channel Logout event URI (spec §2.4).
     * @var string */
    private const LOGOUT_EVENT_URI = 'http://schemas.openid.net/event/backchannel-logout';

    /** @var JsonFactory */
    private readonly JsonFactory $jsonFactory;

    /** @var JwtVerifier */
    private readonly JwtVerifier $jwtVerifier;

    /** @var OidcSessionRegistry */
    private readonly OidcSessionRegistry $sessionRegistry;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /** @var SessionDestructionService */
    private readonly SessionDestructionService $sessionDestructionService;

    /**
     * Initialize back-channel logout controller.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param OAuthUtility                          $oauthUtility
     * @param JsonFactory                           $jsonFactory
     * @param JwtVerifier                           $jwtVerifier
     * @param OidcSessionRegistry                   $sessionRegistry
     * @param OidcRateLimiter                       $rateLimiter
     * @param SessionDestructionService             $sessionDestructionService
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        OAuthUtility $oauthUtility,
        JsonFactory $jsonFactory,
        JwtVerifier $jwtVerifier,
        OidcSessionRegistry $sessionRegistry,
        OidcRateLimiter $rateLimiter,
        SessionDestructionService $sessionDestructionService
    ) {
        $this->jsonFactory               = $jsonFactory;
        $this->jwtVerifier               = $jwtVerifier;
        $this->sessionRegistry           = $sessionRegistry;
        $this->rateLimiter               = $rateLimiter;
        $this->sessionDestructionService = $sessionDestructionService;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Process the IdP back-channel logout POST.
     */
    #[\Override]
    public function execute(): ResultInterface
    {
        // Back-channel requests originate from the IdP server, not end-user browsers,
        // so X-Forwarded-For spoofing is not a concern here. For public-facing OIDC
        // endpoints, consider using REMOTE_ADDR with trusted proxy configuration.
        $request = $this->getRequest();
        $clientIp = ($request instanceof \Magento\Framework\App\Request\Http)
            ? (string) $request->getClientIp()
            : '';
        if (!$this->rateLimiter->isAllowed($clientIp)) {
            $this->oauthUtility->customlog(
                "BackChannelLogout: Rate limit exceeded for IP: " . $clientIp
            );
            return $this->jsonError('Too many requests.', 429);
        }

        $logoutToken = trim((string) $this->getRequest()->getParam('logout_token', ''));

        if ($logoutToken === '') {
            return $this->jsonError('Missing logout_token parameter.', 400);
        }

        // Decode without verification first to extract `iss` for provider lookup
        $claims = $this->jwtVerifier->decodeWithoutVerification($logoutToken);
        if ($claims === null) {
            $this->oauthUtility->customlog('BackChannelLogout: Failed to decode logout_token JWT.');
            return $this->jsonError('Invalid logout_token format.', 400);
        }

        $issuer   = (string) ($claims['iss'] ?? '');
        $audRaw   = $claims['aud'] ?? '';
        // Support multi-audience tokens per OIDC spec
        $audiences = is_array($audRaw)
            ? array_map(static fn($v): string => (string) $v, $audRaw)
            : [(string) $audRaw];

        // Find the provider matching this issuer
        $provider = $this->resolveProvider($issuer);
        if ($provider === null) {
            $this->oauthUtility->customlog(
                "BackChannelLogout: No provider found for issuer={$issuer}"
            );
            return $this->jsonError('Unknown issuer — provider not configured.', 501);
        }

        // Verify JWT signature via the provider's JWKS endpoint
        $jwksEndpoint = (string) ($provider['jwks_endpoint'] ?? '');
        $clientId     = (string) ($provider['clientID'] ?? '');

        // Fail closed when the provider row has no clientID — falling back to
        // the token's own aud claim would make the audience check self-referential
        // and always pass.
        if ($clientId === '') {
            $this->oauthUtility->customlog(
                "BackChannelLogout: ERROR — provider matched by issuer={$issuer} has no clientID"
                . " configured. Fix the provider configuration to accept back-channel logout."
            );
            return $this->jsonError('Provider misconfigured — missing clientID.', 400);
        }

        // Validate audience contains our client ID
        if (!in_array($clientId, $audiences, true)) {
            $this->oauthUtility->customlog(
                "BackChannelLogout: Audience mismatch — clientId={$clientId} not in aud"
            );
            return $this->jsonError('Audience mismatch.', 400);
        }
        $verified     = $this->jwtVerifier->verifyAndDecode($logoutToken, $jwksEndpoint, $issuer, $clientId);

        if ($verified === null) {
            $this->oauthUtility->customlog('BackChannelLogout: JWT signature verification failed.');
            return $this->jsonError('logout_token signature verification failed.', 400);
        }

        // Validate required claims per OIDC Back-Channel Logout spec §2.4
        if (!isset($verified['events'][self::LOGOUT_EVENT_URI])) {
            $this->oauthUtility->customlog('BackChannelLogout: Missing required events claim.');
            return $this->jsonError('logout_token missing required events claim.', 400);
        }

        $sub = trim((string) ($verified['sub'] ?? ''));
        $sid = trim((string) ($verified['sid'] ?? ''));

        if ($sub === '' && $sid === '') {
            $this->oauthUtility->customlog('BackChannelLogout: Neither sub nor sid present in token.');
            return $this->jsonError('logout_token must contain sub or sid.', 400);
        }

        // Resolve the list of registered Magento sessions for this OIDC identity.
        // A single OIDC sub/sid can map to multiple PHP sessions when the user is
        // logged in as both admin and customer simultaneously.
        $entries = $this->sessionRegistry->resolve($sub, $sid);

        if ($entries === null) {
            // Session may have already expired or not been registered — treat as success
            $this->oauthUtility->customlogContext('oidc.backchannel_logout', [
                'sub'    => $sub,
                'sid'    => $sid,
                'result' => 'session_not_found',
            ]);
            return $this->jsonOk('Session not found (already expired or logged out).');
        }

        // Revoke registry entries first to prevent concurrent back-channel
        // logouts from processing the same sessions, then destroy sessions.
        $this->sessionRegistry->revoke($sub, $sid);
        foreach ($entries as $entry) {
            $phpSessionId = (string) ($entry['php_session_id'] ?? '');
            if ($phpSessionId !== '') {
                $this->sessionDestructionService->destroySession($phpSessionId);
                $this->sessionDestructionService->clearOnlineStatus($entry);
            }
        }

        $this->oauthUtility->customlogContext('oidc.backchannel_logout', [
            'sub'    => $sub,
            'sid'    => $sid,
            'result' => 'session_destroyed',
            'count'  => count($entries),
        ]);

        return $this->jsonOk('Session(s) destroyed.');
    }

    // -------------------------------------------------------------------------
    // CsrfAwareActionInterface — IdP calls come from a server, not a browser form
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     *
     * @param RequestInterface $request
     */
    #[\Override]
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // opt out of Magento's form-key CSRF for this endpoint
    }

    /**
     * @inheritdoc
     *
     * @param RequestInterface $request
     */
    #[\Override]
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find the provider row whose `issuer` column matches the token issuer.
     *
     * Falls back to matching against `well_known_config_url` prefix when no
     * exact `issuer` match is found. When more than one active provider
     * matches the issuer, the first match wins and a WARNING is logged.
     *
     * @param  string $issuer Token `iss` claim value
     * @return array<string, mixed>|null Provider data array or null if not found
     */
    private function resolveProvider(string $issuer): ?array
    {
        if ($issuer === '') {
            return null;
        }

        /** @var array<int, array<string, mixed>> $matches */
        $matches = [];
        $providers = $this->oauthUtility->getAllActiveProviders('both');
        foreach ($providers as $provider) {
            // Exact issuer match (preferred)
            if (isset($provider['issuer']) && $provider['issuer'] === $issuer) {
                $matches[] = $provider;
                continue;
            }
            // Fallback: issuer prefix from well-known URL
            $wellKnown = (string) ($provider['well_known_config_url'] ?? '');
            if ($wellKnown !== '') {
                $derivedIssuer = preg_replace(
                    '#/\.well-known/openid-configuration$#i',
                    '',
                    $wellKnown
                );
                if ($derivedIssuer === $issuer) {
                    $matches[] = $provider;
                }
            }
        }

        if ($matches === []) {
            return null;
        }

        // Multiple active providers sharing one issuer is ambiguous —
        // the first match wins, which may use the wrong clientID for the
        // audience check. Log the affected provider ids for the operator.
        if (count($matches) > 1) {
            $ids = implode(
                ', ',
                array_map(
                    static fn(array $p): string => (string) ($p['id'] ?? '?'),
                    $matches
                )
            );
            $this->oauthUtility->customlog(
                "BackChannelLogout: WARNING — multiple active providers match issuer={$issuer}"
                . " (provider ids: {$ids}); first match wins."
            );
        }

        return $matches[0];
    }

    /**
     * Return a JSON 200 OK response.
     *
     * @param string $message
     */
    private function jsonOk(string $message): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setData(['status' => 'ok', 'message' => $message]);
        return $result;
    }

    /**
     * Return a JSON error response.
     *
     * @param string $message
     * @param int    $statusCode
     */
    private function jsonError(string $message, int $statusCode = 400): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setData(['status' => 'error', 'message' => $message]);
        return $result;
    }
}
