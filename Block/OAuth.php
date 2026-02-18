<?php
namespace MiniOrange\OAuth\Block;

use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Customer\Model\Session;
use Magento\Framework\Escaper;

/**
 * Utility block for OAuth/OIDC admin templates.
 *
 * Provides helper methods used by admin and frontend templates to access
 * configuration values, URLs and attribute mappings.
 */
class OAuth extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \MiniOrange\OAuth\Helper\OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var \Magento\Authorization\Model\ResourceModel\Role\Collection
     */
    private $adminRoleModel;

    /**
     * @var \Magento\Customer\Model\ResourceModel\Group\Collection
     */
    private $userGroupModel;

    /**
     * @var Session
     */
    protected $customerSession;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var \Magento\Framework\Data\Form\FormKey
     */
    protected $_formKey;

    /**
     * Initialize OAuth block.
     *
     * @param \Magento\Framework\View\Element\Template\Context           $context
     * @param \MiniOrange\OAuth\Helper\OAuthUtility                      $oauthUtility
     * @param \Magento\Authorization\Model\ResourceModel\Role\Collection $adminRoleModel
     * @param \Magento\Customer\Model\ResourceModel\Group\Collection     $userGroupModel
     * @param Session                                                    $customerSession
     * @param Escaper                                                    $escaper
     * @param \Magento\Framework\Data\Form\FormKey                       $formKey
     * @param array                                                      $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Authorization\Model\ResourceModel\Role\Collection $adminRoleModel,
        \Magento\Customer\Model\ResourceModel\Group\Collection $userGroupModel,
        Session $customerSession,
        Escaper $escaper,
        \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) {
        /**
         * OAuth block constructor.
         *
         * @param \Magento\Framework\View\Element\Template\Context $context
         * @param \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
         * @param \Magento\Authorization\Model\ResourceModel\Role\Collection $adminRoleModel
         * @param \Magento\Customer\Model\ResourceModel\Group\Collection $userGroupModel
         * @param Session $customerSession
         * @param Escaper $escaper
         * @param \Magento\Framework\Data\Form\FormKey $formKey
         * @param array $data
         */
        $this->oauthUtility = $oauthUtility;
        $this->adminRoleModel = $adminRoleModel;
        $this->userGroupModel = $userGroupModel;
        $this->customerSession = $customerSession;
        $this->escaper = $escaper;
        $this->_formKey = $formKey;
        parent::__construct($context, $data);
    }

    /**
     * Get customer session
     *
     * @return Session
     */
    public function getCustomerSession()
    {
        return $this->customerSession;
    }

    /**
     * Escape HTML to prevent XSS
     *
     * @param  array|string      $data        Data to escape
     * @param  array|null|string $allowedTags Allowed HTML tags
     * @return array|string
     */
    public function escapeHtml($data, $allowedTags = null)
    {
        // Normalize allowed tags to array|null as expected by Escaper::escapeHtml
        if (is_string($allowedTags)) {
            // Try to extract tag names from strings like "<b><i>"
            preg_match_all('/<([a-z0-9]+)>/i', $allowedTags, $matches);
            $allowedTags = !empty($matches[1]) ? array_map('strtolower', $matches[1]) : null;
        } elseif (!is_array($allowedTags)) {
            $allowedTags = null;
        }

        return $this->escaper->escapeHtml($data, $allowedTags);
    }

    /**
     * Escape string for HTML attribute
     *
     * @param  string $string
     * @param  bool   $escapeSingleQuote
     * @return string
     */
    public function escapeHtmlAttr($string, $escapeSingleQuote = true)
    {
        return $this->escaper->escapeHtmlAttr($string, $escapeSingleQuote);
    }

    /**
     * Escape URL
     *
     * @param  string $string
     * @return string
     */
    public function escapeUrl($string)
    {
        return $this->escaper->escapeUrl($string);
    }

    /**
     * Escape JavaScript string
     *
     * @param  string $string
     * @return string
     */
    public function escapeJs($string)
    {
        return $this->escaper->escapeJs($string);
    }

    /**
     * Get CSRF form key
     *
     * @return string
     */
    public function getFormKey()
    {
        return $this->_formKey->getFormKey();
    }

    /**
     * Test function to check if the template is being loaded properly in the frontend without any issues.
     *
     * @psalm-return 'Hello world!'
     */
    public function getHelloWorldTxt(): string
    {
        return 'Hello world!';
    }

    /**
     * Check if header sending is enabled.
     *
     * @return string|null
     */
    public function isHeader()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SEND_HEADER);
    }

    /**
     * Check if body sending is enabled.
     *
     * @return string|null
     */
    public function isBody()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SEND_BODY);
    }

    /**
     * Plugin is always enabled (MiniOrange registration removed)
     *
     * @return true
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Get the OAuth grant type from configuration
     */
    public function getGrantType()
    {
        $appName = $this->getAppName();
        if (!empty($appName)) {
            $collection = $this->getAllIdpConfiguration();
            foreach ($collection as $item) {
                if ($item->getData()['app_name'] === $appName) {
                    return $item->getData()['grant_type'] ?? 'authorization_code';
                }
            }
        }
        return 'authorization_code';
    }

    /**
     * Get table mapping configuration (placeholder)
     *
     * @psalm-return ''
     */
    public function getTable(): string
    {
        return '';
    }

    /**
     * Get date of birth attribute mapping
     */
    public function getDobMapping()
    {
        $amDob = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DOB);
        return !$this->oauthUtility->isBlank($amDob) ? $amDob : '';
    }

    /**
     * Get phone number attribute mapping
     */
    public function getPhoneMapping()
    {
        $amPhone = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_PHONE);
        return !$this->oauthUtility->isBlank($amPhone) ? $amPhone : '';
    }

    /**
     * Get street address attribute mapping
     */
    public function getStreetMapping()
    {
        $amStreet = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STREET);
        return !$this->oauthUtility->isBlank($amStreet) ? $amStreet : '';
    }

    /**
     * Get zip code attribute mapping
     */
    public function getZipMapping()
    {
        $amZip = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_ZIP);
        return !$this->oauthUtility->isBlank($amZip) ? $amZip : '';
    }

    /**
     * Get city attribute mapping
     */
    public function getCityMapping()
    {
        $amCity = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_CITY);
        return !$this->oauthUtility->isBlank($amCity) ? $amCity : '';
    }

    /**
     * Get state/region attribute mapping
     */
    public function getStateMapping()
    {
        $amState = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_STATE);
        return !$this->oauthUtility->isBlank($amState) ? $amState : '';
    }

    /**
     * Get country attribute mapping
     */
    public function getCountryMapping()
    {
        $amCountry = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_COUNTRY);
        return !$this->oauthUtility->isBlank($amCountry) ? $amCountry : '';
    }

    /**
     * Get gender attribute mapping
     */
    public function getGenderMapping()
    {
        $amGender = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GENDER);
        return !$this->oauthUtility->isBlank($amGender) ? $amGender : '';
    }

    /**
     * Get OIDC claims for dropdown selection
     *
     * Returns claims received from last test configuration, filtered to remove technical claims
     *
     * @psalm-return list<mixed>
     */
    public function getOidcStandardClaims(): array
    {
        // Technical claims to exclude from dropdown (tokens, timestamps, identifiers)
        $excludedClaims = [
            'sub',
            'iat',
            'rat',
            'at_hash',
            'updated_at',
            'exp',
            'nbf',
            'aud',
            'iss',
            'azp',
            'nonce',
            'auth_time',
            'acr',
            'amr',
            'sid'
        ];

        $storedClaims = $this->oauthUtility->getStoreConfig(OAuthConstants::RECEIVED_OIDC_CLAIMS);
        if (!$this->oauthUtility->isBlank($storedClaims)) {
            $claims = json_decode($storedClaims, true);
            if (is_array($claims) && !empty($claims)) {
                // Filter out technical claims
                return array_values(
                    array_filter(
                        $claims,
                        function ($claim) use ($excludedClaims) {
                            return !in_array($claim, $excludedClaims);
                        }
                    )
                );
            }
        }
        // Return empty array if no test was run yet
        return [];
    }

    /**
     * Get company attribute mapping
     *
     * @psalm-return ''
     */
    public function getCompanyMapping(): string
    {
        return '';
    }

    /**
     * This function checks if OAuth has been configured or not.
     */
    public function isOAuthConfigured(): bool
    {
        return $this->oauthUtility->isOAuthConfigured();
    }

    /**
     * This function fetches the OAuth App name saved by the admin
     */
    public function getAppName()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
    }

    /**
     * This function fetches the Client ID saved by the admin
     */
    public function getClientID()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_ID);
    }

    /**
     * Retrieve the configured endpoint URL.
     *
     * @return string|null
     */
    public function getConfigUrl()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENDPOINT_URL);
    }

    /**
     * This function fetches the Client secret saved by the admin
     */
    public function getClientSecret()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_SECRET);
    }

    /**
     * This function fetches the Scope saved by the admin
     */
    public function getScope()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SCOPE);
    }

    /**
     * This function fetches the Authorize URL saved by the admin
     */
    public function getAuthorizeURL()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_URL);
    }

    /**
     * This function fetches the AccessToken URL saved by the admin
     */
    public function getAccessTokenURL()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ACCESSTOKEN_URL);
    }

    /**
     * This function fetches the GetUserInfo URL saved by the admin
     */
    public function getUserInfoURL()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::GETUSERINFO_URL);
    }

    /**
     * Retrieve the configured logout URL.
     *
     * @return string|null
     */
    public function getLogoutURL()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
    }

    /**
     * Get the admin CSS URL to be appended to the admin dashboard screen.
     */
    public function getAdminCssURL(): string
    {
        return $this->oauthUtility->getAdminCssUrl('adminSettings.css');
    }

    /**
     * Get the current version of the plugin admin dashboard screen.
     *
     * @psalm-return 'v4.2.0'
     */
    public function getCurrentVersion(): string
    {
        return OAuthConstants::VERSION;
    }

    /**
     * Get the admin JS URL to be appended to the admin dashboard pages for plugin functionality
     */
    public function getAdminJSURL(): string
    {
        return $this->oauthUtility->getAdminJSUrl('adminSettings.js');
    }

    /**
     * Get the IntelTelInput JS URL to be appended to admin pages to show country code dropdown on phone number fields.
     */
    public function getIntlTelInputJs(): string
    {
        return $this->oauthUtility->getAdminJSUrl('intlTelInput.min.js');
    }

    /**
     * Fetch/create the TEST Configuration URL of the Plugin.
     *
     * @return string
     */
    public function getTestUrl()
    {
        return $this->getUrl('mooauth/actions/sendAuthorizationRequest');
    }

    /**
     * Get/Create Base URL of the site
     */
    public function getBaseUrl()
    {
        return $this->oauthUtility->getBaseUrl();
    }

    /**
     * Get/Create Base URL of the site
     */
    public function getCallBackUrl(): string
    {
        return $this->oauthUtility->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }

    /**
     * Create the URL for one of the SAML SP plugin sections to be shown as link on any of the template files.
     *
     * @param  string $page
     * @return string
     */
    public function getExtensionPageUrl($page)
    {
        return $this->oauthUtility->getAdminUrl('mooauth/' . $page . '/index');
    }

    /**
     * Read the Tab and retrieve the current active tab if any.
     */
    public function getCurrentActiveTab(): string
    {
        $page = $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => false]);
        $start = strpos($page, '/mooauth') + 9;
        $end = strpos($page, '/index/key');
        $tab = substr($page, $start, $end - $start);
        return $tab;
    }

    /**
     * Check if auto-create admin is enabled.
     *
     * @return string|null
     */
    public function autoCreateAdmin()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
    }

    /**
     * Check if auto-create customer is enabled.
     *
     * @return string|null
     */
    public function autoCreateCustomer()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);
    }

    /**
     * Check if auto-redirect to login is enabled.
     *
     * @return string|null
     */
    public function isLoginRedirectEnabled()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_LOGIN_REDIRECT);
    }

    /**
     * Check if the option to show SSO link on the Admin login page is enabled by the admin.
     */
    public function showAdminLink()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_ADMIN_LINK);
    }

    /**
     * Check if non-OIDC admin login is disabled
     *
     * @return bool
     */
    public function isNonOidcAdminLoginDisabled()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::DISABLE_NON_OIDC_ADMIN_LOGIN);
    }

    /**
     * Check if the option to show SSO link on the Customer login page is enabled by the admin.
     */
    public function showCustomerLink()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
    }

    /**
     * Create/Get the SP initiated URL for the site (frontend/customer login).
     *
     * @param  string|null $relayState
     * @param  string|null $app_name
     * @return string
     */
    public function getSPInitiatedUrl($relayState = null, $app_name = null)
    {
        return $this->oauthUtility->getSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Create/Get the Admin SP initiated URL (admin backend OIDC login).
     *
     * Uses the admin controller which sets loginType=admin.
     *
     * @param  string|null $relayState
     * @param  string|null $app_name
     * @return string
     */
    public function getAdminSPInitiatedUrl($relayState = null, $app_name = null)
    {
        return $this->oauthUtility->getAdminSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Get the configuration of OAuth Server.
     */
    public function getAllIdpConfiguration(): \MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps\Collection
    {
        return $this->oauthUtility->getOAuthClientApps();
    }

    /**
     * Fetch the setting saved by the admin which decides if the account should be mapped to username or email.
     */
    public function getAccountMatcher()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
    }

    /**
     * Fetch the setting saved by the admin which doesn't allow roles to be assigned to unlisted users.
     */
    public function getDisallowUnlistedUserRole()
    {
        $disallowUnlistedRole = $this->oauthUtility->getStoreConfig(OAuthConstants::UNLISTED_ROLE);
        return !$this->oauthUtility->isBlank($disallowUnlistedRole) ? $disallowUnlistedRole : '';
    }

    /**
     * Fetch the setting which doesn't allow users to be created if roles are not mapped.
     */
    public function getDisallowUserCreationIfRoleNotMapped()
    {
        $disallowUserCreationIfRoleNotMapped = $this->oauthUtility->getStoreConfig(OAuthConstants::CREATEIFNOTMAP);
        return !$this->oauthUtility->isBlank($disallowUserCreationIfRoleNotMapped)
            ? $disallowUserCreationIfRoleNotMapped : '';
    }

    /**
     * Fetch the setting which decides what attribute should be mapped to the user's userName.
     */
    public function getUserNameMapping()
    {
        $amUserName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        return !$this->oauthUtility->isBlank($amUserName) ? $amUserName : '';
    }

    /**
     * Check if admin auto-redirect is enabled.
     *
     * @return string|null
     */
    public function getGroupMapping()
    {
        $amGroupName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
        return !$this->oauthUtility->isBlank($amGroupName) ? $amGroupName : '';
    }

    /**
     * Get admin role mappings (OIDC group -> Magento role)
     *
     * @return array Array of mappings with 'group' and 'role' keys
     */
    public function getAdminRoleMappings()
    {
        $mappings = $this->oauthUtility->getStoreConfig('adminRoleMapping');
        if (!$this->oauthUtility->isBlank($mappings)) {
            $decoded = json_decode($mappings, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's Email.
     */
    public function getUserEmailMapping()
    {
        $amEmail = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        return !$this->oauthUtility->isBlank($amEmail) ? $amEmail : '';
    }

    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's firstName.
     */
    public function getFirstNameMapping()
    {
        $amFirstName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        return !$this->oauthUtility->isBlank($amFirstName) ? $amFirstName : '';
    }

    /**
     * Fetch the setting which decides what attribute should be mapped to the user's lastName
     */
    public function getLastNameMapping()
    {
        $amLastName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        return !$this->oauthUtility->isBlank($amLastName) ? $amLastName : '';
    }

    /**
     * Get all admin roles set by the admin on his site.
     */
    public function getAllRoles(): array
    {
        //Apply a filter to only include roles of a certain type ('G' in this case)
        $rolesCollection = $this->adminRoleModel->addFieldToFilter('role_type', 'G');
        // Convert the filtered collection to an options array
        $rolesOptionsArray = $rolesCollection->toOptionArray();
        return $rolesOptionsArray;
    }

    /**
     * Get all customer groups set by the admin on his site.
     */
    public function getAllGroups(): array
    {
        return $this->userGroupModel->toOptionArray();
    }

    /**
     * Get the default role to be set for the user if it doesn't match any of the role/group mappings
     */
    public function getDefaultRole()
    {
        $defaultRole = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE);
        return !$this->oauthUtility->isBlank($defaultRole) ? $defaultRole : OAuthConstants::DEFAULT_ROLE;
    }

    /**
     * Get the Current Admin user from session
     */
    public function getCurrentAdminUser()
    {
        return $this->oauthUtility->getCurrentAdminUser();
    }

    /**
     * Fetch/Create the text of the button to be shown for SP initiated login from the admin / customer login pages.
     */
    public function getSSOButtonText()
    {
        $buttonText = $this->oauthUtility->getStoreConfig(OAuthConstants::BUTTON_TEXT);
        $idpName = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
        return !$this->oauthUtility->isBlank($buttonText) ? $buttonText : 'Login with ' . $idpName;
    }

    /**
     * Get Admin Logout URL for the site
     */
    public function getAdminLogoutUrl(): string
    {
        return $this->oauthUtility->getLogoutUrl();
    }

    /**
     * Is Test Configuration clicked?
     */
    public function getIsTestConfigurationClicked(): bool
    {
        return $this->oauthUtility->getIsTestConfigurationClicked();
    }

    /**
     * Check if log printing is on or off
     */
    public function isDebugLogEnable()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
    }

    /**
     * Fetch the X509 cert saved by the admin for the IDP in the plugin settings.
     */
    public function getX509Cert()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::X509CERT);
    }

    /**
     * Retrieve the configured license type.
     *
     * @return string|null
     */
    public function getJwksUrl()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::JWKS_URL);
    }

    /**
     * Retrieve the current license plan.
     *
     * @return string|null
     */
    public function getProductVersion()
    {
        return $this->oauthUtility->getProductVersion();
    }

    /**
     * Retrieve the license expiry date.
     *
     * @return string|null
     */
    public function getEdition()
    {
        return $this->oauthUtility->getEdition();
    }

    /**
     * Retrieve the current date.
     *
     * @return string|null
     */
    public function getCurrentDate()
    {
        return $this->oauthUtility->getCurrentDate();
    }
    /**
     * Retrieve the stored timestamp.
     *
     * @return string|null
     */
    public function getTimeStamp()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP);
    }

    /**
     * Get OIDC error message from URL parameter
     *
     * @return string|null
     */
    public function getOidcErrorMessage()
    {
        $encodedMessage = $this->getRequest()->getParam('oidc_error');
        if ($encodedMessage) {
            $decoded = $this->oauthUtility->decodeBase64($encodedMessage);
            return $decoded === '' ? null : $decoded;
        }
        return null;
    }

    /**
     * Check if there is an OIDC error
     *
     * @return bool
     */
    public function hasOidcError()
    {
        return $this->getOidcErrorMessage() !== null;
    }

    /**
     * Check if non-OIDC customer login is disabled.
     *
     * Returns true if the configuration setting
     * DISABLE_NON_OIDC_CUSTOMER_LOGIN is enabled, meaning customers
     * can only log in via OIDC and password-based login is blocked.
     *
     * @return bool True if non-OIDC customer login is disabled
     */
    public function isNonOidcCustomerLoginDisabled(): bool
    {
        return (bool) $this->oauthUtility->getStoreConfig(
            OAuthConstants::DISABLE_NON_OIDC_CUSTOMER_LOGIN
        );
    }
}
