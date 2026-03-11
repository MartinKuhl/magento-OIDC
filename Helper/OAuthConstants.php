<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class lists down constant values used all over our Module.
 */
class OAuthConstants
{
    public const string MODULE_DIR = 'MiniOrange_OAuth';
    public const string MODULE_TITLE = 'Authelia OIDC';

    //ACL Settings
    public const string MODULE_OAUTHSETTINGS = '::oauth_settings';
    public const string MODULE_SIGNIN = '::signin_settings';
    public const string MODULE_ATTR = '::attr_settings';

    public const string MODULE_IMAGES = '::images/';
    public const string MODULE_CERTS = '::certs/';
    public const string MODULE_CSS = '::css/';
    public const string MODULE_JS = '::js/';
    public const string MODULE_METADATA = '::metadata/metadata.xml';
    public const string ISSUER_URL_PATH = '/';

    // request option parameter values
    public const string TEST_CONFIG_OPT = 'mooauth_test';

    //database keys

    public const string APP_NAME = 'appName';
    public const string CLIENT_ID = 'clientID';
    public const string CLIENT_SECRET = 'clientSecret';
    public const string SCOPE = 'scope';
    public const string AUTHORIZE_URL = 'authorizeURL';
    public const string ACCESSTOKEN_URL = 'accessTokenURL';
    public const string GETUSERINFO_URL = 'getUserInfoURL';
    public const string OAUTH_LOGOUT_URL = 'oauthLogoutURL';

    public const string TEST_RELAYSTATE = 'testvalidate';
    public const string MAP_MAP_BY = 'amAccountMatcher';
    public const string DEFAULT_MAP_BY = 'email';
    public const string DEFAULT_GROUP = 'General';
    public const string SEND_HEADER = 'header';
    public const string SEND_BODY = 'body';
    public const string ENDPOINT_URL = 'endpoint_url';

    public const string X509CERT = 'certificate';
    public const string JWKS_URL = 'jwks_url';
    public const string ISSUER = 'samlIssuer';
    public const string DB_FIRSTNAME = 'firstname';
    public const string USER_NAME = 'username';
    public const string DB_LASTNAME = 'lastname';
    public const string SHOW_ADMIN_LINK = 'showadminlink';
    public const string SHOW_CUSTOMER_LINK = 'showcustomerlink';
    public const string BUTTON_TEXT = 'buttonText';
    public const string IS_TEST = 'isTest';
    public const string AUTO_CREATE_ADMIN = 'autoCreateAdmin';
    public const string AUTO_CREATE_CUSTOMER = 'autoCreateCustomer';
    public const string ENABLE_LOGIN_REDIRECT = 'enableLoginRedirect';
    public const string DISABLE_NON_OIDC_ADMIN_LOGIN = 'disableNonOidcAdminLogin';
    public const string DISABLE_NON_OIDC_CUSTOMER_LOGIN = 'disableNonOidcCustomerLogin';
    public const string CREATEIFNOTMAP_CUSTOMER = 'createIfNotMapped';

    // Per-provider SSO sync flags (stored in miniorange_oauth_client_apps)
    public const string SYNC_CUSTOMER_PROFILE_ON_SSO = 'sync_customer_profile_on_sso';
    public const string SYNC_CUSTOMER_ADDRESS_ON_SSO = 'sync_customer_address_on_sso';
    public const string SYNC_CUSTOMER_GROUP_ON_SSO   = 'sync_customer_group_on_sso';
    public const string SYNC_ADMIN_PROFILE_ON_SSO    = 'sync_admin_profile_on_sso';
    public const string SYNC_ADMIN_ROLE_ON_SSO       = 'sync_admin_role_on_sso';
    public const string UPDATE_FRONTEND_GROUPS_ON_SSO = 'updateFrontendGroupsOnSso';

    // Login type constants for differentiating admin vs customer OIDC login
    public const string LOGIN_TYPE_CUSTOMER = 'customer';
    public const string LOGIN_TYPE_ADMIN = 'admin';

    // attribute mapping constants
    public const string MAP_EMAIL = 'amEmail';
    public const string DEFAULT_MAP_EMAIL = 'email';
    public const string MAP_USERNAME = 'amUsername';
    public const string DEFAULT_MAP_USERN = 'username';
    public const string MAP_FIRSTNAME = 'amFirstName';
    public const string DEFAULT_MAP_FN = 'firstName';
    public const string DEFAULT_MAP_LN = 'lastName';
    public const string MAP_LASTNAME = 'amLastName';
    public const string MAP_DEFAULT_ROLE = 'defaultRole';
    public const string ADMIN_ROLE_MAPPING = 'adminRoleMapping';
    public const string DEFAULT_ROLE = 'General';
    public const string MAP_GROUP = 'group';
    public const string UNLISTED_ROLE = 'unlistedRole';
    public const string CUSTOMER_GROUP_MAPPING = 'customerGroupMapping';
    public const string MAP_DEFAULT_CUSTOMER_GROUP = 'defaultCustomerGroup';
    public const string DEFAULT_CUSTOMER_GROUP = 'General';

    // Customer data attribute mapping constants
    public const string MAP_DOB = 'amDob';
    public const string DEFAULT_MAP_DOB = 'birthdate';
    public const string MAP_GENDER = 'amGender';
    public const string DEFAULT_MAP_GENDER = 'gender';
    public const string MAP_PHONE = 'amPhone';
    public const string DEFAULT_MAP_PHONE = 'phone_number';
    public const string MAP_STREET = 'amStreet';
    public const string DEFAULT_MAP_STREET = 'address.street_address';
    public const string MAP_ZIP = 'amZip';
    public const string DEFAULT_MAP_ZIP = 'address.postal_code';
    public const string MAP_CITY = 'amCity';
    public const string DEFAULT_MAP_CITY = 'address.locality';
    public const string MAP_STATE = 'amState';
    public const string DEFAULT_MAP_STATE = 'address.region';
    public const string MAP_COUNTRY = 'amCountry';
    public const string DEFAULT_MAP_COUNTRY = 'address.country';

    // Standard OIDC Claims for dropdown selection in admin UI
    public const array OIDC_STANDARD_CLAIMS = [
        'sub',
        'name',
        'given_name',
        'family_name',
        'middle_name',
        'nickname',
        'preferred_username',
        'profile',
        'picture',
        'website',
        'email',
        'email_verified',
        'gender',
        'birthdate',
        'zoneinfo',
        'locale',
        'phone_number',
        'phone_number_verified',
        'updated_at',
        'address.formatted',
        'address.street_address',
        'address.locality',
        'address.region',
        'address.postal_code',
        'address.country'
    ];

    //URLs
    public const string OAUTH_LOGIN_URL = 'mooauth/actions/sendAuthorizationRequest';

    //images
    public const string IMAGE_RIGHT = 'right.png';
    public const string IMAGE_WRONG = 'wrong.png';

    public const string CALLBACK_URL = 'mooauth/actions/ReadAuthorizationResponse';
    public const string CODE = 'code';
    public const string GRANT_TYPE = 'authorization_code';

    // PKCE (RFC 7636) — FEAT-01
    public const string PKCE_VERIFIER_SESSION_KEY = 'oidc_pkce_verifier';
    public const string PKCE_METHOD_S256 = 'S256';
    public const string PKCE_METHOD_PLAIN = 'plain';

    //OAUTH Constants
    public const string OAUTH = 'OAUTH';
    public const string HTTP_REDIRECT = 'HttpRedirect';

    //Registration Status
    public const string STATUS_VERIFY_LOGIN = "MO_VERIFY_CUSTOMER";
    public const string STATUS_COMPLETE_LOGIN = "MO_VERIFIED";

    //plugin constants
    public const string VERSION = "v4.2.0";
    public const string MAGENTO_COUNTER = "magento_count";

    //Debug log
    public const string ENABLE_DEBUG_LOG = 'debug_log';
    public const string LOG_FILE_TIME = 'log_file_time';
    public const string SEND_EMAIL = 'send_email';
    public const string ADMINEMAIL = 'admin_email';
    public const string PLUGIN_VERSION = 'v4.2.0';
    public const string CUSTOMER_EMAIL = 'email';
}
