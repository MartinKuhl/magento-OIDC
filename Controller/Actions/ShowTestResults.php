<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Raw as RawResult;
use Magento\Framework\Controller\ResultFactory;

/**
 * Displays the OIDC attributes received during a test authentication in the frontend.
 *
 * Persists last_test_status and last_test_at to the provider record via
 * saveTestStatusById() (preferred, uses numeric ID from OAuth state) with
 * fallback to saveTestStatus() (legacy, uses app_name from customer session).
 */
class ShowTestResults extends Action
{
    /**
     * @var array|null Attributes received from OIDC provider
     */
    private $attrs;

    /**
     * @var string|null User email
     */
    private $userEmail;

    /**
     * @var string|null Greeting name derived from attributes
     */
    private $greetingName;

    /**
     * @var string|null Status of the test (TEST SUCCESSFUL / TEST FAILED / TEST UNSUCCESSFUL)
     */
    protected $status;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var \Magento\Framework\App\Request\Http */
    protected \Magento\Framework\App\Request\Http $request;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface */
    protected \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;

    /** @var \Magento\Framework\Escaper */
    private readonly \Magento\Framework\Escaper $escaper;

    /** @var \Magento\Customer\Model\Session */
    private readonly \Magento\Customer\Model\Session $customerSession;

    /**
     * @var string Absolute path to the PHTML template used for rendering test results.
     */
    private string $templatePath = '';

    /**
     * Initialize ShowTestResults action.
     *
     * @param Context                                    $context
     * @param OAuthUtility                               $oauthUtility
     * @param \Magento\Framework\App\Request\Http        $request
     * @param ScopeConfigInterface                       $scopeConfig
     * @param \Magento\Customer\Model\Session            $customerSession
     * @param \Magento\Framework\Escaper                 $escaper
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Framework\App\Request\Http $request,
        ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Escaper $escaper
    ) {
        $this->oauthUtility  = $oauthUtility;
        $this->scopeConfig   = $scopeConfig;
        $this->request       = $request;
        $this->customerSession = $customerSession;
        $this->escaper       = $escaper;
        // Absolute path to PHTML template (two dirs up from Controller/Actions/)
        // phpcs:disable Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        $this->templatePath  = dirname(__DIR__, 2)
            . '/view/frontend/templates/test_results.phtml';
        // phpcs:enable Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        parent::__construct($context);
    }

    /**
     * Main entry point: load test data from session, render PHTML template, and return it.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        // Check for OIDC error first
        $oidcError = $this->request->getParam('oidc_error');
        if ($oidcError) {
            return $this->handleOidcError($oidcError);
        }

        // Read the session key from the URL parameter
        $key        = $this->request->getParam('key');
        $testResults = $this->customerSession->getData('mooauth_test_results');
        $attrs      = (is_array($testResults) && isset($testResults[$key])) ? $testResults[$key] : null;
        $this->setAttrs($attrs);
        $this->setUserEmail($attrs['email'] ?? null);
        $this->setGreetingName($attrs);

        // Store received OIDC claims for dropdown selection in Attribute Mapping
        if (!empty($attrs)) {
            $claimKeys = $this->extractClaimKeys($attrs);
            $this->oauthUtility->setStoreConfig(OAuthConstants::RECEIVED_OIDC_CLAIMS, json_encode($claimKeys), true);
            $this->oauthUtility->flushCache();
            $this->oauthUtility->customlog('Stored received OIDC claims: ' . json_encode($claimKeys));
        }

        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog('ShowTestResultsAction: execute');

        $this->status = $this->oauthUtility->isBlank($this->userEmail) ? 'TEST FAILED' : 'TEST SUCCESSFUL';

        // ------------------------------------------------------------------
        // Persist last test status to provider record.
        //
        // Strategy (multi-provider safe):
        //   1. Prefer saveTestStatusById() using the numeric provider ID that
        //      was embedded in the OAuth state parameter and stored in session.
        //   2. Fall back to saveTestStatus() using app_name from session
        //      (legacy single-provider path).
        // ------------------------------------------------------------------
        $testStatus = ($this->status === 'TEST SUCCESSFUL') ? 'success' : 'failed';
        $this->persistTestStatus($testStatus);

        // Track first-use timestamp for MiniOrange telemetry
        $timeStamp = $this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP);
        if ($timeStamp === null) {
            $timeStamp = time();
            $this->oauthUtility->setStoreConfig(OAuthConstants::TIME_STAMP, $timeStamp);
            $this->oauthUtility->flushCache();
        }
        $this->oauthUtility->getBaseUrl();
        $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_EMAIL);
        $this->oauthUtility->getEdition();
        $this->oauthUtility->getProductVersion();
        $this->oauthUtility->getCurrentDate();
        $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        $this->oauthUtility->setStoreConfig(OAuthConstants::SEND_EMAIL_CORE_CONFIG_DATA, 1);
        $this->oauthUtility->flushCache();

        $data = $this->renderTemplate([
            'status'       => $this->status,
            'attrs'        => $this->attrs,
            'greetingName' => $this->greetingName ?? '',
            'rightImage'   => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_RIGHT),
            'wrongImage'   => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG),
            'errorMessage' => '',
        ]);

        /** @var RawResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($data);
        return $result;
    }

    /**
     * Handle OIDC error and display the TEST UNSUCCESSFUL page.
     *
     * @param string $encodedError Base64-encoded error message from the query string
     */
    private function handleOidcError(string $encodedError): \Magento\Framework\Controller\ResultInterface
    {
        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog('ShowTestResultsAction: handleOidcError');

        $errorMessage = $this->oauthUtility->decodeBase64($encodedError);
        $this->status = 'TEST UNSUCCESSFUL';

        // Persist unsuccessful status
        $this->persistTestStatus('unsuccessful');

        $data = $this->renderTemplate([
            'status'       => $this->status,
            'attrs'        => null,
            'greetingName' => '',
            'rightImage'   => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_RIGHT),
            'wrongImage'   => $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG),
            'errorMessage' => $this->escaper->escapeHtml($errorMessage),
        ]);

        /** @var RawResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($data);
        return $result;
    }

    /**
     * Persist the test status to the provider record.
     *
     * Tries saveTestStatusById() first (multi-provider safe, uses numeric ID
     * from OAuth state stored in session key 'mooauth_provider_id').
     * Falls back to saveTestStatus() using app_name from session.
     *
     * @param string $status 'success' | 'failed' | 'unsuccessful'
     */
    private function persistTestStatus(string $status): void
    {
        // Preferred: numeric provider ID embedded in OAuth state during test flow
        $providerId = (int) $this->oauthUtility->getSessionData('mooauth_provider_id');
        if ($providerId > 0) {
            $this->oauthUtility->customlog(
                'ShowTestResults: persisting status "' . $status . '" via provider ID=' . $providerId
            );
            $this->oauthUtility->saveTestStatusById($providerId, $status);
            return;
        }

        // Fallback: app_name from customer session (legacy single-provider path)
        $appName = (string) $this->oauthUtility->getSessionData(OAuthConstants::APP_NAME);
        if ($appName !== '') {
            $this->oauthUtility->customlog(
                'ShowTestResults: persisting status "' . $status . '" via app_name="' . $appName . '"'
            );
            $this->oauthUtility->saveTestStatus($appName, $status);
            return;
        }

        $this->oauthUtility->customlog(
            'ShowTestResults: WARNING – could not persist test status, '
            . 'neither provider ID nor app_name found in session.'
        );
    }

    /**
     * Render the test_results PHTML template with the given variables.
     *
     * Uses output buffering so the template can use plain PHP/HTML without
     * being constrained to Magento's full block/layout rendering stack.
     *
     * @param  array  $vars Associative array of variables to extract into the template scope
     * @return string Rendered HTML
     */
    private function renderTemplate(array $vars): string
    {
        $escaper = $this->escaper; // made available to template via extract()
        extract($vars); // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        ob_start();    // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        include $this->templatePath; // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
        return (string) ob_get_clean();
    }

    /**
     * Set the user attributes for display.
     *
     * @param array|null $attrs
     */
    public function setAttrs($attrs): void
    {
        $this->attrs = $attrs;
    }

    /**
     * Set the user email from the OIDC attributes.
     *
     * @param string|null $email
     */
    public function setUserEmail($email): void
    {
        $this->userEmail = $email;
    }

    /**
     * Derive a greeting name from the OIDC attributes and store it.
     *
     * @param array|null $attrs
     */
    public function setGreetingName($attrs): void
    {
        if (!is_array($attrs)) {
            $this->greetingName = null;
            return;
        }
        $this->greetingName = $attrs['name']
            ?? $attrs['given_name']
            ?? $attrs['preferred_username']
            ?? $attrs['email']
            ?? null;
    }

    /**
     * Extract top-level claim keys from the OIDC attribute array.
     *
     * Flattens one level of nesting so nested objects also contribute keys.
     *
     * @param  array $attrs
     * @return string[] Sorted list of unique claim key names
     */
    private function extractClaimKeys(array $attrs): array
    {
        $keys = array_keys($attrs);
        foreach ($attrs as $value) {
            if (is_array($value)) {
                $keys = array_merge($keys, array_keys($value));
            }
        }
        $keys = array_unique($keys);
        sort($keys);
        return $keys;
    }
}
