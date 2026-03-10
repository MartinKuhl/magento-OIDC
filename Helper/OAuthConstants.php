<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class lists down constant values used all over our Module.
 */
class OAuthConstants
{
    public const MODULE_DIR = 'MiniOrange_OAuth';
    public const MODULE_TITLE = 'Authelia OIDC';

    //ACL Settings
    public const MODULE_OAUTHSETTINGS = '::oauth_settings';
    public const MODULE_SIGNIN = '::signin_settings';
    public const MODULE_ATTR = '::attr_settings';

    public const MODULE_IMAGES = '::images/';
    public const MODULE_CERTS = '::certs/';
    public const MODULE_CSS = '::css/';
    public const MODULE_JS = '::js/';
    public const MODULE_METADATA = '::metadata/metadata.xml';
    public const ISSUER_URL_PATH = '/';

    // request option parameter values
    public const TEST_CONFIG_OPT = 'mooauth_test';

    //database keys

    public const APP_NAME = 'appName';
    public const CLIENT_ID = 'clientID';
    public const CLIENT_SECRET = 'clientSecret';
    public const SCOPE = 'scope';
    public const AUTHORIZE_URL = 'authorizeURL';
    public const ACCESSTOKEN_URL = 'accessTokenURL';
    public const GETUSERINFO_URL = 'getUserInfoURL';
    public const OAUTH_LOGOUT_URL = 'oauthLogoutURL';

    public const TEST_RELAYSTATE = 'testvalidate';
    public const MAP_MAP_BY = 'amAccountMatcher';
    public const DEFAULT_MAP_BY = 'email';
    public const DEFAULT_GROUP = 'General';
    public const SEND_HEADER = 'header';
    public const SEND_BODY = 'body';
    public const ENDPOINT_URL = 'endpoint_url';

    public const X509CERT = 'certificate';
    public const JWKS_URL = 'jwks_url';
    public const ISSUER = 'samlIssuer';
    public const DB_FIRSTNAME = 'firstname';
    public const USER_NAME = 'username';
    public const DB_LASTNAME = 'lastname';
    public const SHOW_ADMIN_LINK = 'showadminlink';
    public const SHOW_CUSTOMER_LINK = 'showcustomerlink';
    public const BUTTON_TEXT = 'buttonText';
    public const IS_TEST = 'isTest';
    public const AUTO_CREATE_ADMIN = 'autoCreateAdmin';
    public const AUTO_CREATE_CUSTOMER = 'autoCreateCustomer';
    public const ENABLE_LOGIN_REDIRECT = 'enableLoginRedirect';
    public const DISABLE_NON_OIDC_ADMIN_LOGIN = 'disableNonOidcAdminLogin';
    public const DISABLE_NON_OIDC_CUSTOMER_LOGIN = 'disableNonOidcCustomerLogin';
    public const CREATEIFNOTMAP_CUSTOMER = 'createIfNotMapped';

    // Per-provider SSO sync flags (stored in miniorange_oauth_client_apps)
    public const SYNC_CUSTOMER_PROFILE_ON_SSO = 'sync_customer_profile_on_sso';
    public const SYNC_CUSTOMER_ADDRESS_ON_SSO = 'sync_customer_address_on_sso';
    public const SYNC_CUSTOMER_GROUP_ON_SSO   = 'sync_customer_group_on_sso';
    public const SYNC_ADMIN_PROFILE_ON_SSO    = 'sync_admin_profile_on_sso';
    public const SYNC_ADMIN_ROLE_ON_SSO       = 'sync_admin_role_on_sso';
    public const UPDATE_FRONTEND_GROUPS_ON_SSO = 'updateFrontendGroupsOnSso';

    // Login type constants for differentiating admin vs customer OIDC login
    public const LOGIN_TYPE_CUSTOMER = 'customer';
    public const LOGIN_TYPE_ADMIN = 'admin';

    // attribute mapping constants
    public const MAP_EMAIL = 'amEmail';
    public const DEFAULT_MAP_EMAIL = 'email';
    public const MAP_USERNAME = 'amUsername';
    public const DEFAULT_MAP_USERN = 'username';
    public const MAP_FIRSTNAME = 'amFirstName';
    public const DEFAULT_MAP_FN = 'firstName';
    public const DEFAULT_MAP_LN = 'lastName';
    public const MAP_LASTNAME = 'amLastName';
    public const MAP_DEFAULT_ROLE = 'defaultRole';
    public const ADMIN_ROLE_MAPPING = 'adminRoleMapping';
    public const DEFAULT_ROLE = 'General';
    public const MAP_GROUP = 'group';
    public const UNLISTED_ROLE = 'unlistedRole';
    public const CUSTOMER_GROUP_MAPPING = 'customerGroupMapping';
    public const MAP_DEFAULT_CUSTOMER_GROUP = 'defaultCustomerGroup';
    public const DEFAULT_CUSTOMER_GROUP = 'General';

    // Customer data attribute mapping constants
    public const MAP_DOB = 'amDob';
    public const DEFAULT_MAP_DOB = 'birthdate';
    public const MAP_GENDER = 'amGender';
    public const DEFAULT_MAP_GENDER = 'gender';
    public const MAP_PHONE = 'amPhone';
    public const DEFAULT_MAP_PHONE = 'phone_number';
    public const MAP_STREET = 'amStreet';
    public const DEFAULT_MAP_STREET = 'address.street_address';
    public const MAP_ZIP = 'amZip';
    public const DEFAULT_MAP_ZIP = 'address.postal_code';
    public const MAP_CITY = 'amCity';
    public const DEFAULT_MAP_CITY = 'address.locality';
    public const MAP_STATE = 'amState';
    public const DEFAULT_MAP_STATE = 'address.region';
    public const MAP_COUNTRY = 'amCountry';
    public const DEFAULT_MAP_COUNTRY = 'address.country';

    // Standard OIDC Claims for dropdown selection in admin UI
    public const OIDC_STANDARD_CLAIMS = [
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
    public const OAUTH_LOGIN_URL = 'mooauth/actions/sendAuthorizationRequest';

    //images
    public const IMAGE_RIGHT = 'right.png';
    public const IMAGE_WRONG = 'wrong.png';

    public const CALLBACK_URL = 'mooauth/actions/ReadAuthorizationResponse';
    public const CODE = 'code';
    public const GRANT_TYPE = 'authorization_code';

    // PKCE (RFC 7636) — FEAT-01
    public const PKCE_VERIFIER_SESSION_KEY = 'oidc_pkce_verifier';
    public const PKCE_METHOD_S256 = 'S256';
    public const PKCE_METHOD_PLAIN = 'plain';

    //OAUTH Constants
    public const OAUTH = 'OAUTH';
    public const HTTP_REDIRECT = 'HttpRedirect';

    //Registration Status
    public const STATUS_VERIFY_LOGIN = "MO_VERIFY_CUSTOMER";
    public const STATUS_COMPLETE_LOGIN = "MO_VERIFIED";

    //plugin constants
    public const VERSION = "v4.2.0";
    public const MAGENTO_COUNTER = "magento_count";

    //Debug log
    public const ENABLE_DEBUG_LOG = 'debug_log';
    public const LOG_FILE_TIME = 'log_file_time';
    public const SEND_EMAIL = 'send_email';
    public const ADMINEMAIL = 'admin_email';
    public const PLUGIN_VERSION = 'v4.2.0';
    public const CUSTOMER_EMAIL = 'email';
}
