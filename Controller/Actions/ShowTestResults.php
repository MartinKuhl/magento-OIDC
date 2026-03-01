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
     * @var string|null Status of the test (TEST SUCCESSFUL / TEST FAILED)
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
     * @param Context $context
     * @param OAuthUtility $oauthUtility
     * @param \Magento\Framework\App\Request\Http $request
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Escaper $escaper
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Framework\App\Request\Http $request,
        ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Escaper $escaper
    ) {
        $this->oauthUtility   = $oauthUtility;
        $this->scopeConfig    = $scopeConfig;
        $this->request        = $request;
        $this->customerSession = $customerSession;
        $this->escaper        = $escaper;
        // Absolute path to PHTML template (two dirs up from Controller/Actions/)
        // phpcs:disable Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        $this->templatePath   = dirname(__DIR__, 2)
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
        $key = $this->request->getParam('key');
        $testResults = $this->customerSession->getData('mooauth_test_results');
        $attrs = (is_array($testResults) && isset($testResults[$key])) ? $testResults[$key] : null;
        $this->setAttrs($attrs);
        $this->setUserEmail($attrs['email'] ?? null);
        $this->setGreetingName($attrs);

        // Store received OIDC claims for dropdown selection in Attribute Mapping
        if (!empty($attrs)) {
            $claimKeys = $this->extractClaimKeys($attrs);
            $this->oauthUtility->setStoreConfig(OAuthConstants::RECEIVED_OIDC_CLAIMS, json_encode($claimKeys), true);
            $this->oauthUtility->flushCache();
            $this->oauthUtility->customlog("Stored received OIDC claims: " . json_encode($claimKeys));
        }

        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog("ShowTestResultsAction: execute");

        $this->status = $this->oauthUtility->isBlank($this->userEmail) ? "TEST FAILED" : "TEST SUCCESSFUL";

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
     * @param  string $encodedError Base64-encoded error message from the query string
     */
    private function handleOidcError(string $encodedError): \Magento\Framework\Controller\ResultInterface
    {
        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog("ShowTestResultsAction: handleOidcError");

        $errorMessage = $this->oauthUtility->decodeBase64($encodedError);
        $this->status = "TEST UNSUCCESSFUL";

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
     * Render the test_results PHTML template with the given variables.
     *
     * Uses output buffering so the template can use plain PHP/HTML without
     * being constrained to Magento's full block/layout rendering stack.
     *
     * @param  array $vars Associative array of variables to extract into the template scope
     * @return string Rendered HTML
     */
    private function renderTemplate(array $vars): string
    {
        $escaper = $this->escaper; // made available to template via extract()
        extract($vars); // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        ob_start(); // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        include $this->templatePath; // phpcs:ignore Magento2.Security.IncludeFile.FoundIncludeFile
        return (string) ob_get_clean();
    }

    /**
     * Set the user attributes for display.
     *
     * @param  array $attrs
     */
    public function setAttrs($attrs): void
    {
        $this->attrs = $attrs;
    }

    /**
     * Set the OAuth exception instance.
     *
     * @param  \Exception|null $exception
     */
    // phpcs:disable Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
    public function setOAuthException($exception): void
    {
        // intentionally empty — exception display is handled via hasExceptionOccurred flag
    }
    // phpcs:enable Magento2.CodeAnalysis.EmptyBlock.DetectedFunction

    /**
     * Set the user email address.
     *
     * @param  string|null $userEmail
     */
    public function setUserEmail($userEmail): void
    {
        $this->userEmail = $userEmail;
    }

    /**
     * Set whether an exception has occurred.
     *
     * @param  bool $hasExceptionOccurred
     */
    // phpcs:disable Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
    public function setHasExceptionOccurred($hasExceptionOccurred): void
    {
    }
    // phpcs:enable Magento2.CodeAnalysis.EmptyBlock.DetectedFunction

    /**
     * Set greeting name with fallback logic: firstName -> username -> email.
     *
     * Uses configured attribute mappings with fallback to common OIDC claim names.
     *
     * @param  array|null $attrs
     */
    private function setGreetingName($attrs): void
    {
        if (empty($attrs)) {
            $this->greetingName = '';
            return;
        }

        // Try to get firstName (configured -> default -> common alternatives)
        $firstName = $this->getAttributeValue(
            $attrs,
            OAuthConstants::MAP_FIRSTNAME,
            [OAuthConstants::DEFAULT_MAP_FN, 'given_name', 'first_name']
        );

        // Try to get username (configured -> default -> common alternatives)
        $username = $this->getAttributeValue(
            $attrs,
            OAuthConstants::MAP_USERNAME,
            [OAuthConstants::DEFAULT_MAP_USERN, 'preferred_username', 'name', 'username']
        );

        // Try to get email (configured -> default -> common alternatives)
        $email = $this->getAttributeValue(
            $attrs,
            OAuthConstants::MAP_EMAIL,
            [OAuthConstants::DEFAULT_MAP_EMAIL, 'mail', 'emailAddress', 'email']
        );

        // Fallback logic: firstName -> username -> email
        if (!$this->oauthUtility->isBlank($firstName)) {
            $this->greetingName = $firstName;
        } elseif (!$this->oauthUtility->isBlank($username)) {
            $this->greetingName = $username;
        } else {
            $this->greetingName = $email ?? '';
        }
    }

    /**
     * Get attribute value using configured mapping with fallback to common claim names
     *
     * @param array    $attrs        User attributes from OIDC provider
     * @param string   $configKey    Configuration key for attribute mapping
     * @param string[] $fallbackKeys Fallback claim names to try
     *
     * @psalm-param 'amEmail'|'amFirstName'|'amUsername' $configKey
     * @psalm-param list{0: 'email'|'firstName'|'username',
     *     1: 'given_name'|'mail'|'preferred_username',
     *     2: 'emailAddress'|'first_name'|'name', 3?: 'email'|'username'} $fallbackKeys
     */
    private function getAttributeValue(array $attrs, string $configKey, array $fallbackKeys)
    {
        // First try configured attribute name
        $configuredAttr = $this->oauthUtility->getStoreConfig($configKey);
        if (!$this->oauthUtility->isBlank($configuredAttr)) {
            $value = $attrs[$configuredAttr] ?? null;
            if (!$this->oauthUtility->isBlank($value)) {
                return is_array($value) ? $value[0] : $value;
            }
        }

        // Fallback: try common claim names
        foreach ($fallbackKeys as $key) {
            $value = $attrs[$key] ?? null;
            if (!$this->oauthUtility->isBlank($value)) {
                return is_array($value) ? $value[0] : $value;
            }
        }

        return null;
    }

    /**
     * Extract all claim keys from OIDC response, including nested paths (e.g., address.locality)
     *
     * @param  mixed  $attrs  The OIDC attributes array
     * @param  string $prefix The prefix for nested keys
     * @return array Array of claim keys
     */
    private function extractClaimKeys($attrs, int|string $prefix = ''): array
    {
        $keys = [];
        if (!is_array($attrs)) {
            return $keys;
        }

        foreach ($attrs as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            $keys[] = $fullKey;

            // If value is an array/object (associative array), recurse to get nested keys
            // Skip indexed arrays (like arrays of values)
            if (is_array($value) && $value !== [] && !isset($value[0])) {
                $nestedKeys = $this->extractClaimKeys($value, $fullKey);
                foreach ($nestedKeys as $nk) {
                    $keys[] = $nk;
                }
            }
        }
        return $keys;
    }
}
