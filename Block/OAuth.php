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


    /**
     * This function retrieves the miniOrange customer Email
     * from the database. To be used on our template pages.
     */

    public function getCustomerEmail()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_EMAIL);
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
     * This function retrieves the miniOrange customer key from the
     * database. To be used on our template pages.
     */
    public function getCustomerKey()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::CUSTOMER_KEY);
    }


    /**
     * This function retrieves the miniOrange API key from the database.
     * To be used on our template pages.
     */
    public function getApiKey()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::API_KEY);
    }


    /**
     * This function retrieves the token key from the database.
     * To be used on our template pages.
     */
    public function getToken()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::TOKEN);
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
        return $this->oauthUtility->getAdminUrl('mooauth/'.$page.'/index');
    }


    /**
     * Reads the Tab and retrieves the current active tab
     * if any.
     */
    public function getCurrentActiveTab()
    {
        $page = $this->getUrl('*/*/*', ['_current' => true, '_use_rewrite' => false]);
              $start = strpos($page, '/mooauth')+9;
        $end = strpos($page, '/index/key');
        $tab = substr($page, $start, $end-$start);
        return $tab;
    }

        /**
     * Just check and return if the user has verified his
     * license key to activate the plugin. Mostly used
     * on the account page to show the verify license key
     * screen.
     */
    public function isVerified()
    {
        return $this->oauthUtility->mclv();
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
     * Create/Get the SP initiated URL for the site.
     */
    public function getSPInitiatedUrl($relayState = null, $app_name = null)
    {
        return $this->oauthUtility->getSPInitiatedUrl($relayState,$app_name);
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
        return !$this->oauthUtility->isBlank($disallowUnlistedRole) ?  $disallowUnlistedRole : '';
    }


    /**
     * This fetches the setting saved by the admin which doesn't allow
     * users to be created if roles are not mapped based on the admin settings.
     */
    public function getDisallowUserCreationIfRoleNotMapped()
    {
        $disallowUserCreationIfRoleNotMapped = $this->oauthUtility->getStoreConfig(OAuthConstants::CREATEIFNOTMAP);
        return !$this->oauthUtility->isBlank($disallowUserCreationIfRoleNotMapped) ?  $disallowUserCreationIfRoleNotMapped : '';
    }


    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's userName.
     */
    public function getUserNameMapping()
    {
        $amUserName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME);
        return !$this->oauthUtility->isBlank($amUserName) ?  $amUserName : '';
    }


    public function getGroupMapping()
    {
        $amGroupName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_GROUP);
        return !$this->oauthUtility->isBlank($amGroupName) ?  $amGroupName : '';
    }

    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's Email.
     */
    public function getUserEmailMapping()
    {
        $amEmail = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL);
        return !$this->oauthUtility->isBlank($amEmail) ?  $amEmail : '';
    }

    /**
     * This fetches the setting saved by the admin which decides what
     * attribute in the SAML response should be mapped to the Magento
     * user's firstName.
     */
    public function getFirstNameMapping()
    {
        $amFirstName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_FIRSTNAME);
        return !$this->oauthUtility->isBlank($amFirstName) ?  $amFirstName : '';
    }


    /**
     * This fetches the setting saved by the admin which decides what
     * attributein the SAML resposne should be mapped to the Magento
     * user's lastName
     */
    public function getLastNameMapping()
    {
        $amLastName = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_LASTNAME);
        return !$this->oauthUtility->isBlank($amLastName) ?  $amLastName : '';
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
        return !$this->oauthUtility->isBlank($defaultRole) ?  $defaultRole : OAuthConstants::DEFAULT_ROLE;
    }


    /**
     * This fetches the registration status in the plugin.
     * Used to detect at what stage is the user at for
     * registration with miniOrange
     */
    public function getRegistrationStatus()
    {
        return $this->oauthUtility->getStoreConfig(OAuthConstants::REG_STATUS);
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
        return !$this->oauthUtility->isBlank($buttonText) ?  $buttonText : 'Login with ' . $idpName;
    }


     /**
      * Get base url of miniorange
      */
    public function getMiniOrangeUrl()
    {
        return $this->oauthUtility->getMiniOrangeUrl();
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
    /* ===================================================================================================
                THE FUNCTIONS BELOW ARE FREE PLUGIN SPECIFIC AND DIFFER IN THE PREMIUM VERSION
       ===================================================================================================
     */


    /**
     * This function checks if the user has completed the registration
     * and verification process. Returns TRUE or FALSE.
     */
    public function isEnabled()
    {
        return $this->oauthUtility->micr();
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

    public function getProductVersion(){
        return  $this->oauthUtility->getProductVersion(); 
    }

    public function getEdition(){
        return $this->oauthUtility->getEdition();
    }

    public function getCurrentDate(){
        return $this->oauthUtility->getCurrentDate();

    }
    public function dataAdded(){
        $this->oauthUtility->setStoreConfig(OAuthConstants::DATA_ADDED,1);
        $this->oauthUtility->flushCache() ;

    }

    public function checkDataAdded(){
        return $this->oauthUtility->getStoreConfig(OAuthConstants::DATA_ADDED);

    }
    public function getTimeStamp(){
        if($this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP) == null){
            $this->oauthUtility->setStoreConfig(OAuthConstants::TIME_STAMP,time());
            $this->oauthUtility->flushCache();
            return time();
        }
        return $this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP);
    }
}
