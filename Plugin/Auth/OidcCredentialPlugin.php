<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\User;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\OidcCredentialAdapter;

/**
 * Plugin for Magento\Backend\Model\Auth
 *
 * Intercepts the admin authentication flow to support OIDC (OpenID Connect)
 * single sign-on. When a login request originates from the OIDC callback,
 * the default credential storage is replaced with OidcCredentialAdapter,
 * which validates the user based on the OIDC identity token rather than
 * a username/password combination.
 *
 * Key responsibilities:
 * - Detect OIDC-originated login requests via the "oidc" request parameter
 * - Replace the credential storage with OidcCredentialAdapter for OIDC logins
 * - Set the session flag "IsOidcAuthenticated" so downstream plugins can
 *   distinguish OIDC users from password-authenticated users
 * - Prevent duplicate log entries for repeated getCredentialStorage() calls
 *
 * @see \MiniOrange\OAuth\Model\OidcCredentialAdapter
 * @see \MiniOrange\OAuth\Plugin\User\OidcPasswordExpirationPlugin
 * @see \MiniOrange\OAuth\Plugin\User\OidcForcePasswordChangePlugin
 */
class OidcCredentialPlugin
{
    /**
     * Indicates whether the current request is an OIDC authentication flow.
     */
    private bool $isOidcAuth = false;

    /**
     * Guard flag to prevent duplicate log entries.
     *
     * getCredentialStorage() is called multiple times during a single login
     * flow (login method, observers, session init). We only want to log once.
     */
    private bool $adapterLogged = false;

    /**
     * @param RequestInterface       $request
     * @param OidcCredentialAdapter   $oidcCredentialAdapter
     * @param AuthSession             $authSession
     * @param OAuthUtility            $oauthUtility
     * @param EventManager            $eventManager
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OidcCredentialAdapter $oidcCredentialAdapter,
        private readonly AuthSession $authSession,
        private readonly OAuthUtility $oauthUtility,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * Before plugin for Auth::login()
     *
     * Detects whether the login request originates from the OIDC callback
     * by checking for the "oidc" request parameter. If present, the plugin
     * sets the internal OIDC flag so that getCredentialStorage() returns
     * the OidcCredentialAdapter instead of the default credential storage.
     *
     * @param Auth   $subject
     * @param string $username
     * @param string $password
     *
     * @return array The original arguments, unmodified
     */
    public function beforeLogin(
        Auth $subject,
        string $username,
        string $password
    ): array {
        if ($this->request->getParam('oidc')) {
            $this->oauthUtility->customlog(
                "OidcCredentialPlugin: OIDC login detected for user: " . $username
            );
            $this->isOidcAuth = true;
            $this->adapterLogged = false;
        }

        return [$username, $password];
    }

    /**
     * Around plugin for Auth::getCredentialStorage()
     *
     * During an OIDC login flow, this replaces Magento's default credential
     * storage (which expects a password hash) with the OidcCredentialAdapter.
     *
     * Note: This method is called multiple times during a single login
     * (by Auth::login(), observers, session initialization, etc.).
     * The log guard ensures we only emit one log entry per login flow.
     *
     * @param Auth     $subject
     * @param callable $proceed
     *
     * @return \Magento\Backend\Model\Auth\Credential\StorageInterface
     */
    public function aroundGetCredentialStorage(
        Auth $subject,
        callable $proceed
    ) {
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
     * Performs post-login cleanup for OIDC authentication:
     * - Sets the session flag "IsOidcAuthenticated" so that other plugins
     *   (e.g. OidcPasswordExpirationPlugin, OidcForcePasswordChangePlugin)
     *   can identify this session as OIDC-authenticated
     * - Resets internal state flags for the next potential login
     *
     * @param Auth  $subject
     * @param mixed $result
     *
     * @return mixed The original result, unmodified
     */
    public function afterLogin(
        Auth $subject,
        $result
    ) {
        if ($this->isOidcAuth) {
            $this->authSession->setIsOidcAuthenticated(true);

            $this->oauthUtility->customlog(
                "OidcCredentialPlugin: OIDC session flag set, cleaning up"
            );

            $this->isOidcAuth = false;
            $this->adapterLogged = false;
        }

        return $result;
    }
}