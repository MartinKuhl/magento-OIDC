<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Observer;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use MiniOrange\OAuth\Controller\Actions\ReadAuthorizationResponse;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\TestResults;
use Psr\Log\LoggerInterface;

/**
 * Main OAuth observer — listens to `controller_action_predispatch`.
 *
 * Checks every incoming request for the `option` query parameter and,
 * when present, routes the request to the appropriate OAuth action
 * (e.g. authorization-code callback, test-config display).
 */
class OAuthObserver implements ObserverInterface
{
    /**
     * Query-parameter keys this observer reacts to.
     */
    private const REQUEST_PARAMS = ['option'];

    /**
     * @param ManagerInterface          $messageManager
     * @param RequestInterface          $request
     * @param ResponseInterface         $response
     * @param OAuthUtility              $oauthUtility
     * @param ReadAuthorizationResponse $readAuthorizationResponse
     * @param TestResults               $testResults
     * @param LoggerInterface           $logger
     */
    public function __construct(
        private readonly ManagerInterface $messageManager,
        private readonly RequestInterface $request,
        private readonly ResponseInterface $response,
        private readonly OAuthUtility $oauthUtility,
        private readonly ReadAuthorizationResponse $readAuthorizationResponse,
        private readonly TestResults $testResults,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle the `controller_action_predispatch` event.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $keys      = array_keys($this->request->getParams());
        $operation = array_intersect($keys, self::REQUEST_PARAMS);

        if (count($operation) === 0) {
            return;
        }

        $isTest = false;

        try {
            $params   = $this->request->getParams();
            $postData = $this->request->getPost();
            $isTest   = (bool) $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

            $this->routeData(array_values($operation)[0], $observer, $params, $postData);
        } catch (\Exception $e) {
            if ($isTest) {
                $output = $this->testResults->output($e, true);
                $this->response->setBody($output);
            }

            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->error('OAuthObserver: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Route the request to the appropriate action based on the `option` parameter.
     *
     * @param string   $op
     * @param Observer $observer
     * @param array    $params
     * @param mixed    $postData
     * @return void
     */
    private function routeData(string $op, Observer $observer, array $params, mixed $postData): void
    {
        if ($op !== self::REQUEST_PARAMS[0]) {
            return;
        }

        $option = $params['option'] ?? '';

        if ($option === OAuthConstants::TEST_CONFIG_OPT) {
            $output = $this->testResults->output(
                null,
                false,
                [
                    'mail'     => $params['mail'] ?? '',
                    'userinfo' => $params['userinfo'] ?? [],
                    'debug'    => $params,
                ]
            );
            $this->response->setBody($output);
        }
    }
}
