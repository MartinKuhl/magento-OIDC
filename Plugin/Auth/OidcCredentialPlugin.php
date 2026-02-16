<?php

declare(strict_types=1);

/**
 * OIDC Credential Plugin
 *
 * Intercepts Auth credential storage retrieval to inject OIDC adapter when
 * OIDC authentication is detected.
 *
 * @package MiniOrange\OAuth\Plugin\Auth
 */
namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Credential\StorageInterface;
use MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcCredentialPlugin
{
    /**
     * @var OidcCredentialAdapter
     */
    protected OidcCredentialAdapter $oidcCredentialAdapter;

    /**
     * @var OAuthUtility
     */
    protected OAuthUtility $oauthUtility;

    /**
     * Flag indicating OIDC authentication is in progress
     *
     * @var bool
     */
    protected bool $isOidcAuth = false;

    /**
     * @param OidcCredentialAdapter $oidcCredentialAdapter
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        OidcCredentialAdapter $oidcCredentialAdapter,
        OAuthUtility $oauthUtility
    ) {
        $this->oidcCredentialAdapter = $oidcCredentialAdapter;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Before plugin for Auth::login()
     *
     * Detects OIDC authentication by checking for OIDC token marker.
     *
     * @param Auth $subject
     * @param string $username
     * @param string $password
     * @return array{0: string, 1: string}
     */
    public function beforeLogin(
        Auth $subject,
        string $username,
        string $password
    ): array {
        if ($password === OidcCredentialAdapter::OIDC_TOKEN_MARKER) {
            $this->oauthUtility->customlog("OidcCredentialPlugin: OIDC authentication detected for: " . $username);
            $this->isOidcAuth = true;
        } else {
            $this->isOidcAuth = false;
        }

        return [$username, $password];
    }

    /**
     * Around plugin for Auth::getCredentialStorage()
     *
     * Returns OIDC adapter when OIDC authentication is active.
     *
     * @param Auth $subject
     * @param callable $proceed
     * @return StorageInterface
     */
    public function aroundGetCredentialStorage(
        Auth $subject,
        callable $proceed
    ): StorageInterface {
        if ($this->isOidcAuth) {
            $this->oauthUtility->customlog("OidcCredentialPlugin: Returning OIDC credential adapter");
            return $this->oidcCredentialAdapter;
        }

        return $proceed();
    }

    /**
     * After plugin for Auth::login()
     *
     * Cleans up OIDC authentication flag after login completes.
     *
     * @param Auth $subject
     * @param null $result Result is always null (Auth::login() returns void)
     * @return void
     */
    public function afterLogin(
        Auth $subject,
        $result
    ): void {
        if ($this->isOidcAuth) {
            $this->oauthUtility->customlog("OidcCredentialPlugin: Cleaning up OIDC flag after login");
            $this->isOidcAuth = false;
        }
    }
}
