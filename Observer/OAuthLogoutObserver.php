<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\App\ResponseInterface;

use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Observer for customer logout events. Handles redirecting to the
 * configured OAuth/OIDC logout URL when the customer logs out.
 * Every Observer class needs to implement ObserverInterface.
 */
class OAuthLogoutObserver implements ObserverInterface
{
    /**
     * @var \MiniOrange\OAuth\Helper\OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $_response;

    /**
     * Initialize OAuth logout observer.
     *
     * @param \MiniOrange\OAuth\Helper\OAuthUtility    $oauthUtility
     * @param \Magento\Framework\App\ResponseInterface $response
     */
    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->_response = $response;
    }

    /**
     * Handle logout event and redirect to OAuth provider logout URL if configured.
     *
     * @param  Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $logoutUrl = $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);

        if (empty($logoutUrl) || !filter_var($logoutUrl, FILTER_VALIDATE_URL)) {
            return;
        }

        if ($this->_response instanceof HttpResponse) {
            $this->_response->setRedirect($logoutUrl);
        }
    }
}
