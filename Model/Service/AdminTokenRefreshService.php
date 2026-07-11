<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Token refresh service for OIDC access token renewal in admin sessions (FEAT-03 admin).
 *
 * Admin-area specialisation of AbstractTokenRefreshService: all RFC 6749 §6
 * refresh logic is inherited; this class only binds the admin AuthSession as
 * token storage and tags structured logs with 'context' => 'admin'.
 *
 * Called automatically by AdminTokenAutoRefreshObserver on every admin request.
 *
 * Storage: Magento admin auth session keys
 *   oidc_access_token           — Magento-encrypted access token (M-01)
 *   oidc_access_token_expires   — Unix timestamp when it expires
 *   oidc_refresh_token          — Magento-encrypted refresh token
 *   oidc_provider_id            — provider row ID (set by Oidccallback)
 */
class AdminTokenRefreshService extends AbstractTokenRefreshService
{
    /** @var AuthSession */
    private readonly AuthSession $authSession;

    /**
     * Initialize admin token refresh service.
     *
     * @param AuthSession        $authSession
     * @param OAuthUtility       $oauthUtility
     * @param Curl               $curl
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        AuthSession $authSession,
        OAuthUtility $oauthUtility,
        Curl $curl,
        EncryptorInterface $encryptor
    ) {
        parent::__construct($oauthUtility, $curl, $encryptor);
        $this->authSession = $authSession;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getSessionData(string $key): mixed
    {
        return $this->authSession->getData($key);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function setSessionData(string $key, mixed $value): void
    {
        $this->authSession->setData($key, $value);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function unsetSessionData(string $key): void
    {
        $this->authSession->unsetData($key);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function isUserLoggedIn(): bool
    {
        return $this->authSession->isLoggedIn();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getLogPrefix(): string
    {
        return 'AdminTokenRefreshService';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getLogContext(): array
    {
        return ['context' => 'admin'];
    }
}
