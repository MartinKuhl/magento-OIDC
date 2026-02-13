<?php
/**
 * OIDC Credential Plugin
 *
 * Intercepts Auth credential storage retrieval to inject OIDC adapter when
 * OIDC authentication is detected. This works with Magento's flow by intercepting
 * getCredentialStorage() instead of fighting against _initCredentialStorage().
 *
 * Strategy:
 * 1. beforeLogin: Detect OIDC authentication and set flag
 * 2. aroundGetCredentialStorage: Return OIDC adapter when flag is set
 * 3. afterLogin: Clean up flag
 *
 * @package MiniOrange\OAuth\Plugin\Auth
 */
namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use MiniOrange\OAuth\Model\Auth\OidcCredentialAdapter;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcCredentialPlugin
{
    /**
     * @var OidcCredentialAdapter
     */
    protected $oidcCredentialAdapter;

    /**
     * @var OAuthUtility
     */
    protected $oauthUtility;

    /**
     * Flag indicating OIDC authentication is in progress
     *
     * @var bool
     */
    protected $isOidcAuth = false;

    /**
     * Store OIDC credentials for the adapter
     *
     * @var array
     */
    protected $oidcCredentials = [];

    /**
     * Constructor
     *
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
     * Sets flag to activate OIDC adapter in getCredentialStorage.
     *
     * @param Auth $subject
     * @param string $username User email from OIDC provider
     * @param string $password OIDC token marker or regular password
     * @return array
     */
    public function beforeLogin(
        Auth $subject,
        $username,
        $password
    ) {
        // Check if this is an OIDC authentication request
        if ($password === OidcCredentialAdapter::OIDC_TOKEN_MARKER) {
            $this->oauthUtility->customlog("OidcCredentialPlugin: OIDC authentication detected for: " . $username);

            // Set flag to activate OIDC adapter
            $this->isOidcAuth = true;

            // Store credentials for the adapter
            $this->oidcCredentials = [
                'username' => $username,
                'password' => $password
            ];
        } else {
            // Standard password authentication
            $this->oauthUtility->customlog("OidcCredentialPlugin: Standard password authentication for: " . $username);
            $this->isOidcAuth = false;
            $this->oidcCredentials = [];
        }

        return [$username, $password];
    }

    /**
     * Around plugin for Auth::getCredentialStorage()
     *
     * Returns OIDC adapter when OIDC authentication is active.
     * This is called after _initCredentialStorage() creates the default storage,
     * but before it's actually used for authentication.
     *
     * @param Auth $subject
     * @param callable $proceed
     * @return \Magento\Backend\Model\Auth\Credential\StorageInterface
     */
    public function aroundGetCredentialStorage(
        Auth $subject,
        callable $proceed
    ) {
        // If OIDC authentication is active, return our adapter
        if ($this->isOidcAuth) {
            $this->oauthUtility->customlog("OidcCredentialPlugin: Returning OIDC credential adapter");
            return $this->oidcCredentialAdapter;
        }

        // Otherwise, return the standard credential storage
        return $proceed();
    }

    /**
     * After plugin for Auth::login()
     *
     * Cleans up OIDC authentication flag after login completes (success or failure).
     * Auth::login() returns void, so $result is always null.
     *
     * @param Auth $subject
     * @param null $result Result of Auth::login() (always null, method returns void)
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
        // KEIN return – die originale Methode Auth::login() gibt void zurück
    }
}
