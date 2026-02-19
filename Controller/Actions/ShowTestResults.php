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
 * Zeigt die empfangenen OIDC-Attribute im Testfall im Frontend an.
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

    /**
     * @var bool
     */
    private $hasExceptionOccurred;

    private readonly OAuthUtility $oauthUtility;

    protected \Magento\Framework\App\Request\Http $request;

    protected \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig;

    private readonly \Magento\Framework\Escaper $escaper;

    private readonly \Magento\Customer\Model\Session $customerSession;

    /**
     * @var string HTML template for the test results page
     */
    private string|array $template = '<div style="font-family:Calibri;padding:0 3%;">{{header}}{{commonbody}}{{footer}}</div>';

    /**
     * @var string HTML header for successful test
     */
    private string $successHeader = '<div style="color: #3c763d;background-color: #dff0d8; padding:2%;'
        . 'margin-bottom:20px;text-align:center; border:1px solid #AEDB9A; '
        . 'font-size:18pt;">TEST SUCCESSFUL</div>'
        . '<div style="display:block;text-align:center;margin-bottom:4%;">'
        . '<img style="width:15%;" src="{{right}}"></div>';
    /**
     * @var string HTML header for failed test
     */
    private string $errorHeader = '<div style="color: #a94442;background-color: #f2dede;padding: 15px;'
        . 'margin-bottom: 20px;text-align:center; border:1px solid #E6B3B2;font-size:18pt;">TEST FAILED</div>'
        . '<div style="display:block;text-align:center;margin-bottom:4%;">'
        . '<img style="width:15%;"src="{{wrong}}"></div>';
    /**
     * @var string HTML header for unsuccessful test
     */
    private string $unsuccessfulHeader = '<div style="color: #a94442;background-color: #f2dede;padding: 15px;'
        . 'margin-bottom: 20px;text-align:center; border:1px solid #E6B3B2;font-size:18pt;">'
        . 'TEST UNSUCCESSFUL</div>'
        . '<div style="display:block;text-align:center;margin-bottom:4%;">'
        . '<img style="width:15%;"src="{{wrong}}"></div>';
    /**
     * @var string HTML template for error message display
     */
    private string $errorBody = '<div style="font-size:14pt;padding:15px;background-color:#fff3cd;'
        . 'border:1px solid #ffc107;border-radius:4px;margin-bottom:20px;">'
        . '<p style="font-weight:bold;color:#856404;margin:0 0 10px 0;">Error Message:</p>'
        . '<p style="color:#856404;margin:0;">{{error_message}}</p></div>';
    /**
     * @var string HTML template for common body with attributes table
     */
    private string|array $commonBody = '<span style="font-size:14pt;"><b>Hello {{greeting_name}},</b></span><br/>'
        . '<p style="font-weight:bold;font-size:14pt;margin-left:1%;">ATTRIBUTES RECEIVED:</p>'
        . '<table style="border-collapse:collapse;border-spacing:0; display:table;width:100%;'
        . 'font-size:14pt;background-color:#EDEDED;">'
        . '<tr style="text-align:center;">'
        . '<td style="font-weight:bold;border:2px solid #949090;padding:2%;">ATTRIBUTE NAME</td>'
        . '<td style="font-weight:bold;padding:2%;border:2px solid #949090; '
        . 'word-wrap:break-word;">ATTRIBUTE VALUE</td>'
        . '</tr>{{tablecontent}}</table>';
    /**
     * @var string HTML template for footer with Done button
     */
    private string $footer = '<div style="margin:3%;display:block;text-align:center;">'
        . '<input style="padding:1%;width:100px;background: #0091CD none repeat scroll 0% 0%;cursor: pointer;'
        . 'font-size:15px;border-width: 1px;border-style: solid;border-radius: 3px;white-space: nowrap;'
        . 'box-sizing: border-box;border-color: #0073AA;'
        . 'box-shadow: 0px 1px 0px rgba(120, 200, 230, 0.6) inset;'
        . 'color: #FFF;" type="button" value="Done" onClick="window.close();"></div>';

    /**
     * @var string HTML template for table row content
     */
    private string $tableContent = "<tr><td style='font-weight:bold;border:2px solid #949090;padding:2%;'>{{key}}</td>"
        . "<td style='padding:2%;border:2px solid #949090; word-wrap:break-word;'>{{value}}</td></tr>";

    /**
     * Initialize ShowTestResults action.
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        \Magento\Framework\App\Request\Http $request,
        ScopeConfigInterface $scopeConfig,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Escaper $escaper
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
        $this->customerSession = $customerSession;
        $this->escaper = $escaper;
        parent::__construct($context);
    }

    /**
     * Hauptfunktion: Daten aus der Session holen (mit Key), anzeigen und Body ausgeben.
     */
    #[\Override]
    public function execute(): \Magento\Framework\Controller\ResultInterface
    {
        // Check for OIDC error first
        $oidcError = $this->request->getParam('oidc_error');
        if ($oidcError) {
            return $this->handleOidcError($oidcError);
        }

        // Test-Key aus URL lesen
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

        $this->processTemplateHeader();
        if (!$this->hasExceptionOccurred) {
            $this->processTemplateContent();
        }
        $this->processTemplateFooter();

        $userEmail = $this->oauthUtility->getStoreConfig(OAuthConstants::ADMINEMAIL);
        if (empty($userEmail)) {
            $userEmail = "No Email Found";
        }
        if (empty($this->status)) {
            $this->status = "TEST FAILED";
        }

        $data = $this->template;
        if ($data === '' || $data === '0') {
            $data = "No attribute found";
        }

        // Tracking für MiniOrange
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

        if ($this->status == "TEST FAILED") {
            $testFailed = json_encode($this->attrs);
            $testSuccessful = '';
        } else {
            $testSuccessful = json_encode($this->attrs);
            $testFailed = '';
        }

        $this->oauthUtility->setStoreConfig(OAuthConstants::SEND_EMAIL_CORE_CONFIG_DATA, 1);
        $this->oauthUtility->flushCache();

        /**
 * @var RawResult $result
*/
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($data);
        return $result;
    }

    /**
     * Handle OIDC error and display TEST UNSUCCESSFUL page
     */
    private function handleOidcError(string $encodedError): \Magento\Framework\Controller\ResultInterface
    {
        if (ob_get_length()) {
            ob_end_clean();
        }
        $this->oauthUtility->customlog("ShowTestResultsAction: handleOidcError");

        $errorMessage = $this->oauthUtility->decodeBase64($encodedError);
        $this->status = "TEST UNSUCCESSFUL";
        $this->hasExceptionOccurred = true;

        // Build the error template
        $header = $this->unsuccessfulHeader;
        $header = str_replace("{{wrong}}", $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG), $header);
        $this->template = str_replace("{{header}}", $header, $this->template);

        // Add error body
        $escapedError = $this->escaper->escapeHtml($errorMessage);
        $errorBodyContent = str_replace("{{error_message}}", $escapedError, $this->errorBody);
        $this->template = str_replace("{{commonbody}}", $errorBodyContent, $this->template);

        // Add footer
        $this->template = str_replace("{{footer}}", $this->footer, $this->template);

        /**
 * @var RawResult $result
*/
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($this->template);
        return $result;
    }

    /**
     * Process the template header based on test result status.
     */
    private function processTemplateHeader(): void
    {
        $header = $this->oauthUtility->isBlank($this->userEmail) ? $this->errorHeader : $this->successHeader;
        $this->status = $this->oauthUtility->isBlank($this->userEmail) ? "TEST FAILED" : "TEST SUCCESSFUL";
        $header = str_replace("{{right}}", $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_RIGHT), $header);
        $header = str_replace("{{wrong}}", $this->oauthUtility->getImageUrl(OAuthConstants::IMAGE_WRONG), $header);
        $this->template = str_replace("{{header}}", $header, $this->template);
    }

    /**
     * Process the template content with user greeting and attributes.
     */
    private function processTemplateContent(): void
    {
        $greet = $this->greetingName ?? '';
        $greetEscaped = $this->escaper->escapeHtml($greet);
        $this->commonBody = str_replace("{{greeting_name}}", $greetEscaped, $this->commonBody);
        $tableContent = array_filter($this->attrs ?? []) ? $this->getTableContent() : "No Attributes Received.";
        //$this->oauthUtility->customlog("ShowTestResultsAction: attribute" . json_encode($this->attrs));
        $this->commonBody = str_replace("{{tablecontent}}", $tableContent, $this->commonBody);
        $this->template = str_replace("{{commonbody}}", (string) $this->commonBody, $this->template);
    }

    /**
     * Build HTML table content from user attributes.
     *
     * @return string
     */
    private function getTableContent()
    {
        $tableContent = '';
        if (is_array($this->attrs)) {
            foreach ($this->attrs as $key => $value) {
                if (!is_array($value)) {
                    $value = [$value];
                }
                if (!in_array(null, $value)) {
                    $escapedKey = $this->escaper->escapeHtml((string) $key);
                    $escapedValues = array_map(
                        function ($v): string {
                            return (string) $this->escaper->escapeHtml((string) $v);
                        },
                        $value
                    );
                    $tableContent .= str_replace(
                        "{{key}}",
                        $escapedKey,
                        str_replace(
                            "{{value}}",
                            implode("<br/>", $escapedValues),
                            $this->tableContent
                        )
                    );
                }
            }
        }
        return $tableContent;
    }

    /**
     * Process the template footer section.
     */
    private function processTemplateFooter(): void
    {
        $this->template = str_replace("{{footer}}", $this->footer, $this->template);
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
     * @return void
     */
    public function setOAuthException($exception)
    {
    }

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
    public function setHasExceptionOccurred($hasExceptionOccurred): void
    {
        $this->hasExceptionOccurred = $hasExceptionOccurred;
    }

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
