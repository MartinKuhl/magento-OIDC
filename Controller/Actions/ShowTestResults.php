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

        // Store received OIDC claims per-provider for dropdown selection in Attribute Mapping
        if (!empty($attrs)) {
            $claimKeys = $this->extractClaimKeys($attrs);
            $providerId = (int) $this->request->getParam('provider_id');

            if ($providerId > 0) {
                // Per-provider: save to miniorange_oauth_client_apps.received_oidc_claims
                $this->oauthUtility->saveReceivedOidcClaims($providerId, $claimKeys);
                $this->oauthUtility->customlog(
                    'Stored received OIDC claims for provider ID=' . $providerId . ': ' . json_encode($claimKeys)
                );
            } else {
                $this->oauthUtility->customlog(
                    'WARNING: Could not store OIDC claims – no provider_id in request.'
                );
            }

            $this->oauthUtility->flushCache();
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

        
        $this->oauthUtility->getBaseUrl();
        $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_EMAIL);
        $this->oauthUtility->getEdition();
        $this->oauthUtility->getProductVersion();
        $this->oauthUtility->getCurrentDate();
        $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
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
     * Priority:
     *   1. provider_id from URL parameter (redirect-safe, set by ReadAuthorizationResponse)
     *   2. app_name from customer session (legacy fallback)
     *
     * @param string $status 'success' | 'failed' | 'unsuccessful'
     */
    private function persistTestStatus(string $status): void
    {
        // 1. Preferred: provider_id from URL parameter (redirect-safe)
        $providerId = (int) $this->request->getParam('provider_id');

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
     * @param  array  $vars Associative array of variables to extract into the template scope
     * @return string Rendered HTML
     */
    private function renderTemplate(array $vars): string
    {
        $escaper = $this->escaper;
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
     * Extract claim keys from the OIDC attribute array.
     *
     * Flattens nested objects using dot-notation (e.g. address.locality).
     * Skips numeric array indices to avoid entries like "0", "1".
     *
     * @param  array      $attrs  OIDC attributes
     * @param  int|string $prefix Dot-notation prefix for recursion
     * @return string[]   Flat list of claim key names
     */
    private function extractClaimKeys($attrs, int|string $prefix = ''): array
    {
        $keys = [];
        if (!is_array($attrs)) {
            return $keys;
        }

        foreach ($attrs as $key => $value) {
            // Skip numeric array indices (e.g., groups: ["admin", "user"] → 0, 1)
            if (is_int($key)) {
                continue;
            }

            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (is_array($value) && !$this->isIndexedArray($value)) {
                // Nested associative object: only add dotted sub-keys, NOT the parent key
                $keys = array_merge($keys, $this->extractClaimKeys($value, $fullKey));
            } else {
                $keys[] = $fullKey;
            }
        }

        return $keys;
    }

    /**
     * Check if array is numerically indexed (list) vs associative (object).
     */
    private function isIndexedArray(array $arr): bool
    {
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
