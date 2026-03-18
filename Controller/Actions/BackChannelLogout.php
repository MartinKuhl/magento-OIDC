<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Session\SessionManagerInterface;
use M2Oidc\OAuth\Helper\JwtVerifier;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;

/**
 * Back-channel (server-to-server) OIDC logout endpoint (FEAT-02).
 *
 * Route: POST /m2oidc/actions/backchannel-logout
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
    /** OIDC Back-Channel Logout event URI (spec §2.4). */
    // H-07: Typed constants (private const string) require PHP 8.3+. Magento 2.4 supports PHP 8.1+.
    private const LOGOUT_EVENT_URI = 'http://schemas.openid.net/event/backchannel-logout';

    /** @var JsonFactory */
    private readonly JsonFactory $jsonFactory;

    /** @var JwtVerifier */
    private readonly JwtVerifier $jwtVerifier;

    /** @var OidcSessionRegistry */
    private readonly OidcSessionRegistry $sessionRegistry;

    /** @var OidcRateLimiter */
    private readonly OidcRateLimiter $rateLimiter;

    /**
     * Initialize back-channel logout controller.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param OAuthUtility                          $oauthUtility
     * @param JsonFactory                           $jsonFactory
     * @param JwtVerifier                           $jwtVerifier
     * @param OidcSessionRegistry                   $sessionRegistry
     * @param OidcRateLimiter                       $rateLimiter
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        OAuthUtility $oauthUtility,
        JsonFactory $jsonFactory,
        JwtVerifier $jwtVerifier,
        OidcSessionRegistry $sessionRegistry,
        OidcRateLimiter $rateLimiter
    ) {
        $this->jsonFactory     = $jsonFactory;
        $this->jwtVerifier     = $jwtVerifier;
        $this->sessionRegistry = $sessionRegistry;
        $this->rateLimiter     = $rateLimiter;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Process the IdP back-channel logout POST.
     */
    #[\Override]
    public function execute(): ResponseInterface
    {
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
        $audience = is_array($audRaw) ? ($audRaw[0] ?? '') : (string) $audRaw;

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
        $clientId     = (string) ($provider['clientID'] ?? $audience);
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

        $sub = (string) ($verified['sub'] ?? '');
        $sid = (string) ($verified['sid'] ?? '');

        if ($sub === '' && $sid === '') {
            $this->oauthUtility->customlog('BackChannelLogout: Neither sub nor sid present in token.');
            return $this->jsonError('logout_token must contain sub or sid.', 400);
        }

        // Resolve the Magento session ID from the registry
        $phpSessionId = $this->sessionRegistry->resolve($sub, $sid);

        if ($phpSessionId === null) {
            // Session may have already expired or not been registered — treat as success
            $this->oauthUtility->customlogContext('oidc.backchannel_logout', [
                'sub'    => $sub,
                'sid'    => $sid,
                'result' => 'session_not_found',
            ]);
            return $this->jsonOk('Session not found (already expired or logged out).');
        }

        // Destroy the target session by switching to it and regenerating
        $this->destroySession($phpSessionId);
        $this->sessionRegistry->revoke($sub, $sid);

        $this->oauthUtility->customlogContext('oidc.backchannel_logout', [
            'sub'    => $sub,
            'sid'    => $sid,
            'result' => 'session_destroyed',
        ]);

        return $this->jsonOk('Session destroyed.');
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
     * exact `issuer` match is found.
     *
     * @param  string $issuer Token `iss` claim value
     * @return array|null Provider data array or null if not found
     */
    private function resolveProvider(string $issuer): ?array
    {
        if ($issuer === '') {
            return null;
        }

        $providers = $this->oauthUtility->getAllActiveProviders('both');
        foreach ($providers as $provider) {
            // Exact issuer match (preferred)
            if (isset($provider['issuer']) && $provider['issuer'] === $issuer) {
                /** @psalm-suppress InvalidReturnStatement */
                return $provider;
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
                    return $provider;
                }
            }
        }
        return null;
    }

    /**
     * Destroy a PHP session by its session ID.
     *
     * C-02: Back-channel logout requires destroying a session that belongs to a
     * different browser request. PHP does not provide an API for this; switching
     * session IDs via session_id() is the de-facto standard approach used by
     * Symfony, Laravel, and other frameworks.
     *
     * Thread safety: PHP's file-based session handler holds an exclusive flock()
     * for the duration of the session_start() call, so concurrent reads from the
     * target session's owner request will block until we call session_destroy().
     * The try-finally below ensures the original session ID is always restored even
     * if an exception is thrown (e.g. by an observer on session_start).
     *
     * @param string $phpSessionId Target PHP session ID
     */
    private function destroySession(string $phpSessionId): void
    {
        if (!preg_match('/^[a-zA-Z0-9,-]+$/', $phpSessionId)) {
            // Reject malformed session IDs to prevent path traversal in file-based handlers
            $this->oauthUtility->customlog(
                "BackChannelLogout: Rejected malformed session ID during destroy."
            );
            return;
        }
        // Use the session save handler to delete the session data file/record
        // without disrupting the current request's session.
        // phpcs:disable Magento2.Functions.DiscouragedFunction.Discouraged, Magento2.Security.Superglobal
        $currentId = session_id();
        session_commit();
        session_id($phpSessionId);
        try {
            session_start(['read_and_close' => false]);
            $_SESSION = [];
            session_destroy();
        } finally {
            // C-02: Always restore original session ID, even if an exception is thrown
            session_id($currentId !== false ? $currentId : '');
            if ($currentId !== false && $currentId !== '') {
                session_start(['read_and_close' => false]);
            }
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction.Discouraged, Magento2.Security.Superglobal
    }

    /**
     * Return a JSON 200 OK response.
     *
     * @param string $message
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    private function jsonOk(string $message): ResponseInterface
    {
        $result = $this->jsonFactory->create();
        $result->setData(['status' => 'ok', 'message' => $message]);
        // @phpstan-ignore return.type
        return $result;
    }

    /**
     * Return a JSON error response.
     *
     * @param string $message
     * @param int    $statusCode
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    private function jsonError(string $message, int $statusCode = 400): ResponseInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($statusCode);
        $result->setData(['status' => 'error', 'message' => $message]);
        // @phpstan-ignore return.type
        return $result;
    }
}
