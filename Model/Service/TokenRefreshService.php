<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Token refresh service for OIDC access token renewal (FEAT-03).
 *
 * Customer-area specialisation of AbstractTokenRefreshService: all RFC 6749 §6
 * refresh logic is inherited; this class only binds the Magento customer
 * session as token storage.
 *
 * At login time, the caller should persist the refresh_token (encrypted) into
 * the customer session via `storeTokens()`. When a request needs a fresh
 * access token, call `refreshIfNeeded()`.
 *
 * Storage: Magento customer session keys
 *   oidc_access_token           — Magento-encrypted access token (M-01)
 *   oidc_access_token_expires   — Unix timestamp when it expires
 *   oidc_refresh_token          — Magento-encrypted refresh token
 *   oidc_provider_id            — provider row ID (set by CheckAttributeMappingAction)
 */
class TokenRefreshService extends AbstractTokenRefreshService
{
    /** @var CustomerSession */
    private readonly CustomerSession $customerSession;

    /**
     * Initialize token refresh service.
     *
     * @param OAuthUtility       $oauthUtility
     * @param Curl               $curl
     * @param EncryptorInterface $encryptor
     * @param CustomerSession    $customerSession
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        Curl $curl,
        EncryptorInterface $encryptor,
        CustomerSession $customerSession
    ) {
        parent::__construct($oauthUtility, $curl, $encryptor);
        $this->customerSession = $customerSession;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getSessionData(string $key): mixed
    {
        return $this->customerSession->getData($key);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function setSessionData(string $key, mixed $value): void
    {
        $this->customerSession->setData($key, $value);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function unsetSessionData(string $key): void
    {
        $this->customerSession->unsetData($key);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function isUserLoggedIn(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getLogPrefix(): string
    {
        return 'TokenRefreshService';
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function getLogContext(): array
    {
        return [];
    }
}
