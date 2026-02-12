<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\ResponseInterface;

/**
 * Observer for customer logout events. Handles redirecting to the
 * configured OAuth/OIDC logout URL when the customer logs out.
 * Every Observer class needs to implement ObserverInterface.
 */
class OAuthLogoutObserver implements ObserverInterface
{
    private $oauthUtility;
    protected $_response;

    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResponseInterface $response
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->_response = $response;
    }

    /**
     * This function is called as soon as the observer class is initialized.
     * Checks if the request parameter has any of the configured request
     * parameters and handles any exception that the system might throw.
     *
     * @param $observer
     * @return
     */
    public function execute(Observer $observer)
    {
        $logoutUrl = $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
        if (!empty($logoutUrl)) {
            // Validate URL scheme to prevent javascript: or data: XSS
            $parsed = parse_url($logoutUrl);
            if ($parsed === false || !in_array($parsed['scheme'] ?? '', ['https', 'http'], true)) {
                return;
            }
            $this->_response->setRedirect($logoutUrl);
            return $this->_response;
        }
    }

}
