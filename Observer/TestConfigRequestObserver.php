<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Observer;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use M2Oidc\OAuth\Controller\Actions\ReadAuthorizationResponse;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Helper\TestResults;
use Psr\Log\LoggerInterface;

/**
 * Test-config request observer — listens to `controller_action_predispatch`.
 *
 * Detects requests carrying `option=m2oidc_test` (`OAuthConstants::TEST_CONFIG_OPT`)
 * in the query string and renders the attribute-mapping test results inline
 * via the TestResults helper instead of the dispatched controller output.
 */
class TestConfigRequestObserver implements ObserverInterface
{
    /**
     * Query-parameter keys this observer reacts to.
     * @var mixed[]
     */
    private const REQUEST_PARAMS = ['option'];

    /**
     * Initialize test-config request observer.
     *
     * @param ManagerInterface $messageManager
     * @param HttpRequest      $request
     * @param HttpResponse     $response
     * @param OAuthUtility     $oauthUtility
     * @param TestResults      $testResults
     * @param LoggerInterface  $logger
     */
    public function __construct(
        private readonly ManagerInterface $messageManager,
        private readonly HttpRequest $request,
        private readonly HttpResponse $response,
        private readonly OAuthUtility $oauthUtility,
        private readonly TestResults $testResults,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle the `controller_action_predispatch` event.
     *
     * @param Observer $observer
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        $keys      = array_keys($this->request->getParams());
        $operation = array_intersect($keys, self::REQUEST_PARAMS);

        if ($operation === []) {
            return;
        }

        $isTest = false;

        try {
            $params   = $this->request->getParams();
            $postData = $this->request->getPost();
            $isTest   = (bool) $this->oauthUtility->getStoreConfig(OAuthConstants::IS_TEST);

            $this->routeData(array_values($operation)[0], $params);
        } catch (\Exception $e) {
            if ($isTest) {
                $output = $this->testResults->output($e, true);
                $this->response->setBody($output);
            }

            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->error('TestConfigRequestObserver: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Route the request to the appropriate action based on the `option` parameter.
     *
     * @param string  $op
     * @param mixed[] $params
     */
    private function routeData(string $op, array $params): void
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
