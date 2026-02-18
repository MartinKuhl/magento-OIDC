<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;

use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Observer for customer logout events. Handles redirecting to the
 * configured OAuth/OIDC logout URL when the customer logs out.
 * Every Observer class needs to implement ObserverInterface.
 */
class OAuthLogoutObserver implements ObserverInterface
{
    private \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    protected \Magento\Framework\App\ResponseInterface $_response;

    private CookieManagerInterface $cookieManager;

    private CookieMetadataFactory $cookieMetadataFactory;

    /**
     * Initialize OAuth logout observer.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     *        OAuth utility helper
     * @param ResponseInterface $response HTTP response interface
     * @param CookieManagerInterface $cookieManager Cookie manager
     * @param CookieMetadataFactory $cookieMetadataFactory Cookie
     *        metadata factory
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response,
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->_response = $response;
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
    }

    /**
     * Handle logout event and redirect to OAuth provider logout URL.
     *
     * Cleans up the OIDC customer cookie before redirecting to the
     * configured OAuth logout URL. Cookie cleanup is best-effort and
     * will not prevent the logout redirect if it fails.
     *
     * @param Observer $observer Event observer
     */
    public function execute(Observer $observer): void
    {
        // Clean up OIDC customer cookie on logout
        try {
            $oidcCookie = $this->cookieManager->getCookie(
                'oidc_customer_authenticated'
            );
            if ($oidcCookie !== null) {
                $cookieMetadata = $this->cookieMetadataFactory
                    ->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setHttpOnly(true)
                    ->setSecure(true);
                $this->cookieManager->deleteCookie(
                    'oidc_customer_authenticated',
                    $cookieMetadata
                );
                $this->oauthUtility->customlog(
                    "OAuthLogoutObserver: OIDC customer cookie deleted"
                );
            }
        } catch (\Exception $e) {
            // Silent fail — cookie cleanup is best-effort
            $this->oauthUtility->customlog(
                "OAuthLogoutObserver: Error deleting OIDC cookie: "
                . $e->getMessage()
            );
        }

        // Redirect to OIDC logout URL if configured
        $logoutUrl = $this->oauthUtility->getStoreConfig(
            OAuthConstants::OAUTH_LOGOUT_URL
        );

        if (empty($logoutUrl)
            || !filter_var($logoutUrl, FILTER_VALIDATE_URL)
        ) {
            return;
        }

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);
        }
    }
}
