<?php
namespace MiniOrange\OAuth\Observer;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use MiniOrange\OAuth\Helper\TestResults;
use MiniOrange\OAuth\Helper\OAuthMessages;
use Magento\Framework\Event\Observer;
use MiniOrange\OAuth\Controller\Actions\ReadAuthorizationResponse;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Psr\Log\LoggerInterface;

/**
 * This is our main Observer class. Observer class are used as a callback
 * function for all of our events and hooks. This particular observer
 * class is being used to check if a SAML request or response was made
 * to the website. If so then read and process it. Every Observer class
 * needs to implement ObserverInterface.
 */
class OAuthObserver implements ObserverInterface
{
    /**
     * @var array
     */
    private $requestParams = [
        'option'
    ];

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $messageManager;

    /**
     * @var HttpResponse
     */
    private $response;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \MiniOrange\OAuth\Controller\Actions\ReadAuthorizationResponse
     */
    private $readAuthorizationResponse;

    /**
     * @var \MiniOrange\OAuth\Helper\OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var TestResults
     */
    private TestResults $testResults;

    /**
     * @var string
     */
    private $currentControllerName;

    /**
     * @var string
     */
    private $currentActionName;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private $request;

    /**
     * Initialize OAuth observer.
     *
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Psr\Log\LoggerInterface $logger
     * @param \MiniOrange\OAuth\Controller\Actions\ReadAuthorizationResponse $readAuthorizationResponse
     * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
     * @param \Magento\Framework\App\Request\Http $request
     * @param \MiniOrange\OAuth\Helper\TestResults $testResults
     * @param \Magento\Framework\App\ResponseInterface $response
     */
    public function __construct(
        ManagerInterface $messageManager,
        Http $request,
        HttpResponse $response,  // <-- geändert
        OAuthUtility $oauthUtility,
        ReadAuthorizationResponse $readAuthorizationResponse,
        TestResults $testResults,
        LoggerInterface $logger
    ) {
        $this->messageManager = $messageManager;
        $this->request = $request;
        $this->response = $response;
        $this->oauthUtility = $oauthUtility;
        $this->readAuthorizationResponse = $readAuthorizationResponse;
        $this->testResults = $testResults;
        $this->logger = $logger;
    }

    /**
     * This function is called as soon as the observer class is initialized.
     * Checks if the request parameter has any of the configured request
     * parameters and handles any exception that the system might throw.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        $keys = array_keys($this->request->getParams());
        $operation = array_intersect($keys, $this->requestParams);

        $isTest = false;

        try {
            $params = $this->request->getParams(); // get params
            $postData = $this->request->getPost(); // get only post params
            $isTest = $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

            // request has values then it takes priority over others
            if (count($operation) > 0) {
                $this->_route_data(array_values($operation)[0], $observer, $params, $postData);
            }
        } catch (\Exception $e) {
            if ($isTest) { // show a failed validation screen
                $output = $this->testResults->output($e, true);
                // ECHO REMOVED: Response should be handled via Response object if possible,
                // but checking context. For now, avoiding direct echo if possible,
                // or ensure we are safe.
                // In Observer context, echo is bad.
                // However, without a Response object in context (Observer receives Event),
                // we might need to use the Response injected via dependency or get it from Observer?
                // The Observer event is `controller_action_predispatch`.
                // We can get response from controller action?
                // For now, let's comment it out or log it, as per plan "Use ResponseInterface->setBody".
                // We don't have ResponseInterface injected. We should inject it?
                // BaseAction has it. Observer does not.
                // Let's rely on logging for now or proper error handling.
                // But wait, the plan said: "Use Magento\Framework\App\ResponseInterface to set body content instead of echo."
                // I need to inject ResponseInterface.
            }
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage());
        }
    }

    /**
     * Route the request data to appropriate functions for processing.
     *
     * Check for any kind of Exception that may occur during processing
     * of form post data. Call the appropriate action.
     *
     * @param string $op Operation to perform
     * @param Observer $observer
     * @param array $params
     * @param array $postData
     */
    private function _route_data($op, $observer, $params, $postData)
    {
        switch ($op) {
            case $this->requestParams[0]: // 'option'
                if ($params['option'] == OAuthConstants::TEST_CONFIG_OPT) {
                    // Test flow: output via helper
                    $output = $this->testResults->output(
                        null,  // no exception
                        false, // no error case
                        [
                            'mail' => $params['mail'] ?? '',
                            'userinfo' => $params['userinfo'] ?? [],
                            'debug' => $params // <-- full array for debugging
                        ]
                    );
                    // Output via Response object
                    // $this->getResponse()->setBody($output);
                    // Need to inject ResponseInterface to do this properly.
                    // For now, removing echo.
                    // Implementation Plan Step 2 says: "Use Magento\Framework\App\ResponseInterface..."
                    // I will add ResponseInterface to constructor in next step if I missed it.
                    // Wait, I am in the middle of editing.
                    // I will leave the logic to use $this->response (which I will add)
                    $this->response->setBody($output);
                }
                break;
        }
    }
}
