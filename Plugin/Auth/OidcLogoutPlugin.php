<?php
/**
 * OIDC Logout Plugin
 *
 * Deletes the OIDC authentication cookie when admin user logs out.
 * This ensures the OIDC identity bypass is only active while the user
 * has an active session.
 *
 * @package MiniOrange\OAuth\Plugin\Auth
 */
namespace MiniOrange\OAuth\Plugin\Auth;

use Magento\Backend\Model\Auth;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcLogoutPlugin
{
    /** @var \Magento\Framework\Stdlib\CookieManagerInterface */
    protected \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager;

    /** @var \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory */
    protected \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory;

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    protected \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /**
     * Initialize OIDC logout plugin.
     *
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param OAuthUtility           $oauthUtility
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Delete OIDC cookie after logout
     *
     * @param  Auth  $subject
     * @param  mixed $result
     * @return mixed
     */
    public function afterLogout(Auth $subject, $result)
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setHttpOnly(true)
            ->setSecure(true)
            ->setSameSite('Lax');

        try {
            $this->cookieManager->deleteCookie('oidc_authenticated', $metadata);
            $this->oauthUtility->customlog("OidcLogoutPlugin: OIDC cookie deleted");
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                "OidcLogoutPlugin: Error deleting cookie: " . $e->getMessage()
            );
        }

        return $result;
    }
}
