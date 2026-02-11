<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\Event\ObserverInterface;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\Event\Observer;

use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Response\RedirectInterface;

/**
 * Observer for customer logout events. Handles redirecting to the
 * configured OAuth/OIDC logout URL when the customer logs out.
 * Every Observer class needs to implement ObserverInterface.
 */
class OAuthLogoutObserver implements ObserverInterface
{
    private $requestParams = [
        'option'
    ];
    private $messageManager;
    private $logger;
    private $readAuthorizationResponse;
    private $oauthUtility;
    private $testAction;
    private $currentControllerName;
    private $currentActionName;
    private $requestInterface;
    private $request;
    protected $_redirect;
    protected $_response;

    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Psr\Log\LoggerInterface $logger,
        \MiniOrange\OAuth\Controller\Actions\ReadAuthorizationResponse $readAuthorizationResponse,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Framework\App\Request\Http $httpRequest,
        \Magento\Framework\App\RequestInterface $request,
        \MiniOrange\OAuth\Controller\Actions\ShowTestResults $testAction,
        RedirectInterface $redirect,
        ResponseInterface $response
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->readAuthorizationResponse = $readAuthorizationResponse;
        $this->oauthUtility = $oauthUtility;
        $this->currentControllerName = $httpRequest->getControllerName();
        $this->currentActionName = $httpRequest->getActionName();
        $this->request = $request;
        $this->testAction = $testAction;
        $this->_redirect = $redirect;
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
            $temp = '<script>window.location = ' . json_encode($logoutUrl, JSON_UNESCAPED_SLASHES) . ';</script>';
            return $this->_response->setBody($temp);
        }
    }

    private function _route_data($op, $observer, $params, $postData)
    {
        switch ($op) {
            case $this->requestParams[0]:
                // Spezieller Test-Konfigurations-Button
                if (isset($params['option']) && $params['option'] === OAuthConstants::TEST_CONFIG_OPT) {
                    // Direkt den Test-Result-Controller (wie im alten Code) aufrufen und Response zurückgeben!
                    $this->testAction->execute();
                } else {
                    // Sonst wie bisher - OIDC Callback
                    $this->oauthUtility->customlog('Admin login request detected but handled via OIDC callback');
                }
                break;
        }
    }

}
