<?php

declare(strict_types=1);

/**
 * OIDC Credential Plugin
 *
 * Intercepts Auth credential storage retrieval to inject OIDC adapter when
 * OIDC authentication is detected.
 *
 * @package M2Oidc\OAuth\Plugin\Auth
 */
namespace M2Oidc\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Credential\StorageInterface;
use M2Oidc\OAuth\Model\Auth\OidcCredentialAdapter;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\OAuthSecurityHelper;

class OidcCredentialPlugin
{
    /** @var OidcCredentialAdapter */
    private readonly OidcCredentialAdapter $oidcCredentialAdapter;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var OAuthSecurityHelper */
    private readonly OAuthSecurityHelper $securityHelper;

    /**
     * @var bool Flag indicating OIDC authentication is in progress
     */
    private bool $isOidcAuth = false;

    /**
     * @var bool Guard flag to prevent duplicate log entries.
     *
     * getCredentialStorage() is called multiple times during a single login
     * flow (login method, observers, session init). We only log once.
     */
    private bool $adapterLogged = false;

    /**
     * Initialize OIDC credential plugin.
     *
     * @param OidcCredentialAdapter $oidcCredentialAdapter
     * @param OAuthUtility          $oauthUtility
     * @param OAuthSecurityHelper   $securityHelper
     */
    public function __construct(
        OidcCredentialAdapter $oidcCredentialAdapter,
        OAuthUtility $oauthUtility,
        OAuthSecurityHelper $securityHelper
    ) {
        $this->oidcCredentialAdapter = $oidcCredentialAdapter;
        $this->oauthUtility = $oauthUtility;
        $this->securityHelper = $securityHelper;
    }

    /**
     * Before plugin for Auth::login()
     *
     * Detects OIDC authentication by checking for OIDC token marker.
     *
     * @param  Auth   $subject
     * @param  string $username
     * @param  string $password
     * @return array{0: string, 1: string}
     */
    public function beforeLogin(
        Auth $subject,
        string $username,
        string $password
    ): array {
        // SEC-06: Always unconditionally reset both flags at the start of every login
        // attempt. This guards against the edge case where a prior Auth::login() call
        // threw an exception before afterLogin() could execute, leaving $isOidcAuth=true
        // in a recycled PHP-FPM worker process for the next incoming request.
        $this->isOidcAuth    = false;
        $this->adapterLogged = false;

        // C-01: Detect OIDC login by checking for the ephemeral token format (non-consuming)
        if ($this->securityHelper->isOidcAuthToken($password)) {
            $this->oauthUtility->customlog(
                "OidcCredentialPlugin: OIDC authentication detected for: " . $username
            );
            $this->isOidcAuth = true;
        }

        return [$username, $password];
    }

    /**
     * Around plugin for Auth::getCredentialStorage()
     *
     * During an OIDC login flow, this replaces Magento's default credential
     * storage with the OidcCredentialAdapter.
     *
     * Note: This method is called multiple times during a single login
     * (by Auth::login(), observers, session initialization, etc.).
     * The log guard ensures we only emit one log entry per login flow.
     *
     * @param  Auth     $subject
     * @param  callable $proceed
     */
    public function aroundGetCredentialStorage(
        Auth $subject,
        callable $proceed
    ): StorageInterface {
        if ($this->isOidcAuth) {
            if (!$this->adapterLogged) {
                $this->oauthUtility->customlog(
                    "OidcCredentialPlugin: Returning OIDC credential adapter"
                );
                $this->adapterLogged = true;
            }

            return $this->oidcCredentialAdapter;
        }

        return $proceed();
    }

    /**
     * After plugin for Auth::login()
     *
     * Cleans up OIDC authentication flag after login completes.
     *
     * @param  Auth $subject
     * @param  null $result  Result is always null (Auth::login() returns void)
     */
    public function afterLogin(
        Auth $subject,
        $result
    ): void {
        if ($this->isOidcAuth) {
            $this->oauthUtility->customlog(
                "OidcCredentialPlugin: Cleaning up OIDC flag after login"
            );
            $this->isOidcAuth = false;
            $this->adapterLogged = false;
        }
    }
}
