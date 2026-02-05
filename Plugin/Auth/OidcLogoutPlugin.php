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
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

class OidcLogoutPlugin
{
    /**
     * @var CookieManagerInterface
     */
    protected $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    protected $cookieMetadataFactory;

    /**
     * @var BackendUrlInterface
     */
    protected $backendUrl;

    /**
     * @var OAuthUtility
     */
    protected $oauthUtility;

    /**
     * Constructor
     *
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param BackendUrlInterface $backendUrl
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        BackendUrlInterface $backendUrl,
        OAuthUtility $oauthUtility
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->backendUrl = $backendUrl;
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * Delete OIDC cookie after logout
     *
     * @param Auth $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterLogout(Auth $subject, $result)
    {
        // Get admin path dynamically (supports custom admin URLs)
        $adminPath = '/' . $this->backendUrl->getAreaFrontName();

        // Delete OIDC cookie on logout
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath($adminPath);

        try {
            $this->cookieManager->deleteCookie('oidc_authenticated', $metadata);
            $this->oauthUtility->customlog("OidcLogoutPlugin: OIDC cookie deleted for path: " . $adminPath);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("OidcLogoutPlugin: Error deleting cookie: " . $e->getMessage());
        }

        return $result;
    }
}
