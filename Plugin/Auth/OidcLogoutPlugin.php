<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Cleans up OIDC cookies on admin logout and sets a short-lived
 * logout cookie to suppress the auto-redirect on the next login page visit.
 */
class OidcLogoutPlugin
{
    private const OIDC_AUTH_COOKIE = 'oidc_authenticated';
    private const LOGOUT_COOKIE_NAME = 'oidc_admin_just_logged_out';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory  $cookieMetadataFactory,
        private readonly OAuthUtility           $oauthUtility
    ) {
    }

    public function afterLogout(Auth $subject): void
    {
        // 1. Delete the OIDC auth cookie
        $deleteMeta = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setPath('/');

        try {
            $this->cookieManager->deleteCookie(self::OIDC_AUTH_COOKIE, $deleteMeta);
            $this->oauthUtility->customlog('OidcLogoutPlugin: OIDC cookie deleted');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error deleting OIDC cookie: ' . $e->getMessage()
            );
        }

        // 2. Set logout guard cookie to suppress auto-redirect
        try {
            $guardMeta = $this->cookieMetadataFactory
                ->createPublicCookieMetadata()
                ->setDuration(120)
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSameSite('Lax');

            $this->cookieManager->setPublicCookie(
                self::LOGOUT_COOKIE_NAME,
                '1',
                $guardMeta
            );
            $this->oauthUtility->customlog('OidcLogoutPlugin: Logout guard cookie set');
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'OidcLogoutPlugin: Error setting logout cookie: ' . $e->getMessage()
            );
        }
    }
}
