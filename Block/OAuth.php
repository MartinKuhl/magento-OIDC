<?php
namespace MiniOrange\OAuth\Block;

use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Customer\Model\Session;
use Magento\Framework\Escaper;

/**
 * This class is used to denote our admin block for all our
 * backend templates. This class has certain commmon
 * functions which can be called from our admin template pages.
 */
class OAuth extends \Magento\Framework\View\Element\Template
{


    private $oauthUtility;
    private $adminRoleModel;
    private $userGroupModel;
    protected $customerSession;
    protected $escaper;
    protected $_formKey;

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
        $this->oauthUtility = $oauthUtility;
        $this->adminRoleModel = $adminRoleModel;
        $this->userGroupModel = $userGroupModel;
        $this->customerSession = $customerSession;
        $this->escaper = $escaper;
        $this->_formKey = $formKey;
        parent::__construct($context, $data);
    }

    public function getCustomerSession()  // Returns the customer session
    {
        return $this->customerSession;
    }

    public function escapeHtml($data, $allowedTags = null)        // Escapes HTML to prevent XSS
    {
        return $this->escaper->escapeHtml($data, $allowedTags);
    }


    public function escapeHtmlAttr($string, $escapeSingleQuote = true)      // Escapes strings for HTML attributes
    {
        return $this->escaper->escapeHtmlAttr($string, $escapeSingleQuote);
    }


    public function escapeUrl($string)    // Escapes URLs to make them safe
    {
        return $this->escaper->escapeUrl($string);
    }

    public function escapeJs($data)        // Escapes JavaScript content
    {
        return $this->escaper->escapeJs($data);
    }

    public function getFormKey()
    {
        return $this->_formKey->getFormKey(); // _formKey injected via constructor
    }


    /**
     * This function is a test function to check if the template
     * is being loaded properly in the frontend without any issues.
     */
    public function getHelloWorldTxt()
    {
        return 'Hello world!';
    }


    public function isHeader()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SEND_HEADER);
    }


    public function isBody()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SEND_BODY);
    }

    /**
     * Plugin is always enabled (MiniOrange registration removed)
     */
    public function isEnabled()
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
     */
    public function getTable()
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
     * Returns claims received from last test configuration, filtered to remove technical claims
     */
    public function getOidcStandardClaims()
    {
        // Technical claims to exclude from dropdown (tokens, timestamps, identifiers)
        $excludedClaims = ['sub', 'iat', 'rat', 'at_hash', 'updated_at', 'exp', 'nbf', 'aud', 'iss', 'azp', 'nonce', 'auth_time', 'acr', 'amr', 'sid'];

        $storedClaims = $this->oauthUtility->getStoreConfig(OAuthConstants::RECEIVED_OIDC_CLAIMS);
        if (!$this->oauthUtility->isBlank($storedClaims)) {
            $claims = json_decode($storedClaims, true);
            if (is_array($claims) && !empty($claims)) {
                // Filter out technical claims
                return array_values(array_filter($claims, function ($claim) use ($excludedClaims) {
                    return !in_array($claim, $excludedClaims);
                }));
            }
        }
        // Return empty array if no test was run yet
        return [];
    }

    /**
     * Get company attribute mapping
     */
    public function getCompanyMapping()
    {
        return '';
    }

    /**
     * This function checks if OAuth has been configured or not.
     */
    public function isOAuthConfigured()
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


    public function getLogoutURL()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::OAUTH_LOGOUT_URL);
    }

    /**
     * This function gets the admin CSS URL to be appended to the
     * admin dashboard screen.
     */
    public function getAdminCssURL()
    {
        return $this->oauthUtility->getAdminCssUrl('adminSettings.css');
    }

    /**
     * This function gets the current version of the plugin
     * admin dashboard screen.
     */
    public function getCurrentVersion()
    {
        return OAuthConstants::VERSION;
    }


    /**
     * This function gets the admin JS URL to be appended to the
     * admin dashboard pages for plugin functionality
     */
    public function getAdminJSURL()
    {
        return $this->oauthUtility->getAdminJSUrl('adminSettings.js');
    }


    /**
     * This function gets the IntelTelInput JS URL to be appended
     * to admin pages to show country code dropdown on phone number
     * fields.
     */
    public function getIntlTelInputJs()
    {
        return $this->oauthUtility->getAdminJSUrl('intlTelInput.min.js');
    }


    /**
     * This function fetches/creates the TEST Configuration URL of the
     * Plugin.
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
    public function getCallBackUrl()
    {
        return $this->oauthUtility->getBaseUrl() . OAuthConstants::CALLBACK_URL;
    }


    /**
     * Create the URL for one of the SAML SP plugin
     * sections to be shown as link on any of the
     * template files.
     */
    public function getExtensionPageUrl($page)
    {
        return $this->oauthUtility->getAdminUrl('mooauth/' . $page . '/index');
    }


    /**
     * Reads the Tab and retrieves the current active tab
     * if any.
     */
    public function getCurrentActiveTab()
    {
        $page = $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => false]);
        $start = strpos($page, '/mooauth') + 9;
        $end = strpos($page, '/index/key');
        $tab = substr($page, $start, $end - $start);
        return $tab;
    }

    public function autoCreateAdmin()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_ADMIN);
    }

    public function autoCreateCustomer()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::AUTO_CREATE_CUSTOMER);
    }

    public function isLoginRedirectEnabled()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_LOGIN_REDIRECT);
    }

    /**
     * Is the option to show SSO link on the Admin login page enabled
     * by the admin.
     */
    public function showAdminLink()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_ADMIN_LINK);
    }


    /**
     * Is the option to show SSO link on the Customer login page enabled
     * by the admin.
     */
    public function showCustomerLink()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK);
    }

    /**
     * Create/Get the SP initiated URL for the site (frontend/customer login).
     */
    public function getSPInitiatedUrl($relayState = null, $app_name = null)
    {
        return $this->oauthUtility->getSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Create/Get the Admin SP initiated URL (admin backend OIDC login).
     * Uses the admin controller which sets loginType=admin.
     */
    public function getAdminSPInitiatedUrl($relayState = null, $app_name = null)
    {
        return $this->oauthUtility->getAdminSPInitiatedUrl($relayState, $app_name);
    }

    /**
     * Get the configuration of OAuth Server.
     */
    public function getAllIdpConfiguration()
    {
        return $this->oauthUtility->getOAuthClientApps();
    }

    /**
     * This fetches the setting saved by the admin which decides if the
     * account should be mapped to username or email in Magento.
     */
    public function getAccountMatcher()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_MAP_BY);
    }

    /**
     * This fetches the setting saved by the admin which doesn't allow
     * roles to be assigned to unlisted users.
     */
    public function getDisallowUnlistedUserRole()
    {
        $disallowUnlistedRole = $this->oauthUtility->getStoreConfig(OAuthConstants::UNLISTED_ROLE);
        return !$this->oauthUtility->isBlank($disallowUnlistedRole) ? $disallowUnlistedRole : '';
    }


    /**
     * This fetches the setting saved by the admin which doesn't allow
     * users to be created if roles are not mapped based on the admin settings.
     */
    public function getDisallowUserCreationIfRoleNotMapped()
    {
        $disallowUserCreationIfRoleNotMapped = $this->oauthUtility->getStoreConfig(OAuthConstants::CREATEIFNOTMAP);
        return !$this->oauthUtility->isBlank($disallowUserCreationIfRoleNotMapped) ? $disallowUserCreationIfRoleNotMapped : '';
    }


    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's userName.
     */
    public function getUserNameMapping()
    {
        $amUserName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        return !$this->oauthUtility->isBlank($amUserName) ? $amUserName : '';
    }


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
     * This fetches the setting saved by the admin which decides what
     * attributein the SAML resposne should be mapped to the Magento
     * user's lastName
     */
    public function getLastNameMapping()
    {
        $amLastName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        return !$this->oauthUtility->isBlank($amLastName) ? $amLastName : '';
    }


    /**
     * Get all admin roles set by the admin on his site.
     */
    public function getAllRoles()
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
    public function getAllGroups()
    {
        return $this->userGroupModel->toOptionArray();
    }


    /**
     * Get the default role to be set for the user if it
     * doesn't match any of the role/group mappings
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
     * Fetches/Creates the text of the button to be shown
     * for SP inititated login from the admin / customer
     * login pages.
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
    public function getAdminLogoutUrl()
    {
        return $this->oauthUtility->getLogoutUrl();
    }

    /**
     * Is Test Configuration clicked?
     */
    public function getIsTestConfigurationClicked()
    {
        return $this->oauthUtility->getIsTestConfigurationClicked();
    }

    /**
     * check if log printing is on or off
     */
    public function isDebugLogEnable()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
    }
    /**
     * This function fetches the X509 cert saved by the admin for the IDP
     * in the plugin settings.
     */
    public function getX509Cert()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::X509CERT);
    }

    public function getJwksUrl()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::JWKS_URL);
    }

    public function getProductVersion()
    {
        return $this->oauthUtility->getProductVersion();
    }

    public function getEdition()
    {
        return $this->oauthUtility->getEdition();
    }

    public function getCurrentDate()
    {
        return $this->oauthUtility->getCurrentDate();

    }
    public function getTimeStamp()
    {
        if ($this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP) == null) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::TIME_STAMP, time());
            // $this->oauthUtility->flushCache(); // REMOVED for performance
            return time();
        }
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
            return base64_decode($encodedMessage);
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
}
