<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

/**
 * This class lists down constant values used all over our Module.
 */
class OAuthConstants
{
    /**
     * @var string
     */
    public const MODULE_DIR = 'M2Oidc_OAuth';
    /**
     * @var string
     */
    public const MODULE_TITLE = 'Authelia OIDC';

    /**
     * @var string
     */
    public const MODULE_IMAGES = '::images/';
    /**
     * @var string
     */
    public const MODULE_CERTS = '::certs/';
    /**
     * @var string
     */
    public const MODULE_CSS = '::css/';
    /**
     * @var string
     */
    public const MODULE_JS = '::js/';
    /**
     * @var string
     */
    public const MODULE_METADATA = '::metadata/metadata.xml';
    /**
     * @var string
     */
    public const ISSUER_URL_PATH = '/';

    // request option parameter values
    /**
     * @var string
     */
    public const TEST_CONFIG_OPT = 'm2oidc_test';

    //database keys
    /**
     * @var string
     */
    public const APP_NAME = 'appName';
    /**
     * @var string
     */
    public const CLIENT_ID = 'clientID';
    /**
     * @var string
     */
    public const CLIENT_SECRET = 'clientSecret';
    /**
     * @var string
     */
    public const SCOPE = 'scope';
    /**
     * @var string
     */
    public const AUTHORIZE_URL = 'authorizeURL';
    /**
     * @var string
     */
    public const ACCESSTOKEN_URL = 'accessTokenURL';
    /**
     * @var string
     */
    public const GETUSERINFO_URL = 'getUserInfoURL';
    /**
     * @var string
     */
    public const OAUTH_LOGOUT_URL = 'oauthLogoutURL';

    /**
     * @var string
     */
    public const TEST_RELAYSTATE = 'testvalidate';
    /**
     * @var string
     */
    public const MAP_MAP_BY = 'amAccountMatcher';
    /**
     * @var string
     */
    public const DEFAULT_MAP_BY = 'email';
    /**
     * @var string
     */
    public const DEFAULT_GROUP = 'General';
    /**
     * @var string
     */
    public const SEND_HEADER = 'header';
    /**
     * @var string
     */
    public const SEND_BODY = 'body';
    /**
     * @var string
     */
    public const ENDPOINT_URL = 'endpoint_url';

    /**
     * @var string
     */
    public const X509CERT = 'certificate';
    /**
     * @var string
     */
    public const JWKS_URL = 'jwks_url';
    /** Per-provider JWKS public-key cache TTL in seconds. Default 86400 (24 h).
     * @var string */
    public const JWKS_CACHE_TTL = 'jwks_cache_ttl';
    /** Per-provider HTTP connect/read timeout in seconds.
     * @var string */
    public const HTTP_TIMEOUT         = 'http_timeout';
    /**
     * @var int
     */
    public const HTTP_TIMEOUT_DEFAULT = 30;
    /**
     * @var string
     */
    public const ISSUER = 'samlIssuer';
    /**
     * @var string
     */
    public const DB_FIRSTNAME = 'firstname';
    /**
     * @var string
     */
    public const USER_NAME = 'username';
    /**
     * @var string
     */
    public const DB_LASTNAME = 'lastname';
    /**
     * @var string
     */
    public const SHOW_ADMIN_LINK = 'showadminlink';
    /**
     * @var string
     */
    public const SHOW_CUSTOMER_LINK = 'showcustomerlink';
    /**
     * @var string
     */
    public const BUTTON_TEXT = 'buttonText';
    /**
     * @var string
     */
    public const IS_TEST = 'isTest';
    /**
     * @var string
     */
    public const AUTO_CREATE_ADMIN = 'autoCreateAdmin';
    /**
     * @var string
     */
    public const AUTO_CREATE_CUSTOMER = 'autoCreateCustomer';
    /**
     * @var string
     */
    public const ENABLE_LOGIN_REDIRECT = 'enableLoginRedirect';
    /**
     * @var string
     */
    public const DISABLE_NON_OIDC_ADMIN_LOGIN = 'disableNonOidcAdminLogin';
    /**
     * @var string
     */
    public const DISABLE_NON_OIDC_CUSTOMER_LOGIN = 'disableNonOidcCustomerLogin';
    /**
     * @var string
     */
    public const CREATEIFNOTMAP_CUSTOMER = 'createIfNotMapped';

    // Per-provider SSO sync flags (stored in m2oidc_oauth_client_apps)
    /**
     * @var string
     */
    public const SYNC_CUSTOMER_PROFILE_ON_SSO = 'sync_customer_profile_on_sso';
    /**
     * @var string
     */
    public const SYNC_CUSTOMER_ADDRESS_ON_SSO = 'sync_customer_address_on_sso';
    /**
     * @var string
     */
    public const SYNC_CUSTOMER_GROUP_ON_SSO   = 'sync_customer_group_on_sso';
    /**
     * @var string
     */
    public const SYNC_ADMIN_PROFILE_ON_SSO    = 'sync_admin_profile_on_sso';
    /**
     * @var string
     */
    public const SYNC_ADMIN_ROLE_ON_SSO       = 'sync_admin_role_on_sso';
    /**
     * @var string
     */
    public const UPDATE_FRONTEND_GROUPS_ON_SSO = 'updateFrontendGroupsOnSso';

    // Login type constants for differentiating admin vs customer OIDC login
    /**
     * @var string
     */
    public const LOGIN_TYPE_CUSTOMER = 'customer';
    /**
     * @var string
     */
    public const LOGIN_TYPE_ADMIN = 'admin';

    // IdP-Initiated SSO (OIDC Third-Party Initiated Login §4)
    /**
     * @var string
     */
    public const IDP_INITIATED_ENABLED = 'idpInitiatedEnabled';

    // Claim value encoding applied during attribute flattening
    /**
     * @var string
     */
    public const CLAIM_ENCODING        = 'claimEncoding';
    /**
     * @var string
     */
    public const CLAIM_ENCODING_NONE   = 'none';
    /**
     * @var string
     */
    public const CLAIM_ENCODING_BASE64 = 'base64';

    // attribute mapping constants
    /**
     * @var string
     */
    public const MAP_EMAIL = 'amEmail';
    /**
     * @var string
     */
    public const DEFAULT_MAP_EMAIL = 'email';
    /**
     * @var string
     */
    public const MAP_USERNAME = 'amUsername';
    /**
     * @var string
     */
    public const DEFAULT_MAP_USERN = 'username';
    /**
     * @var string
     */
    public const MAP_FIRSTNAME = 'amFirstName';
    /**
     * @var string
     */
    public const DEFAULT_MAP_FN = 'firstName';
    /**
     * @var string
     */
    public const DEFAULT_MAP_LN = 'lastName';
    /**
     * @var string
     */
    public const MAP_LASTNAME = 'amLastName';
    /**
     * @var string
     */
    public const MAP_DEFAULT_ROLE = 'defaultRole';
    /**
     * @var string
     */
    public const ADMIN_ROLE_MAPPING = 'adminRoleMapping';
    /**
     * @var string
     */
    public const DEFAULT_ROLE = 'General';
    /**
     * @var string
     */
    public const MAP_GROUP = 'group';
    /**
     * @var string
     */
    public const UNLISTED_ROLE = 'unlistedRole';
    /**
     * @var string
     */
    public const CUSTOMER_GROUP_MAPPING = 'customerGroupMapping';
    /**
     * @var string
     */
    public const MAP_DEFAULT_CUSTOMER_GROUP = 'defaultCustomerGroup';
    /**
     * @var string
     */
    public const DEFAULT_CUSTOMER_GROUP = 'General';

    // Customer data attribute mapping constants
    /**
     * @var string
     */
    public const MAP_DOB = 'amDob';
    /**
     * @var string
     */
    public const DEFAULT_MAP_DOB = 'birthdate';
    /**
     * @var string
     */
    public const MAP_GENDER = 'amGender';
    /**
     * @var string
     */
    public const DEFAULT_MAP_GENDER = 'gender';
    /**
     * @var string
     */
    public const MAP_PHONE = 'amPhone';
    /**
     * @var string
     */
    public const DEFAULT_MAP_PHONE = 'phone_number';
    /**
     * @var string
     */
    public const MAP_STREET = 'amStreet';
    /**
     * @var string
     */
    public const DEFAULT_MAP_STREET = 'address.street_address';
    /**
     * @var string
     */
    public const MAP_ZIP = 'amZip';
    /**
     * @var string
     */
    public const DEFAULT_MAP_ZIP = 'address.postal_code';
    /**
     * @var string
     */
    public const MAP_CITY = 'amCity';
    /**
     * @var string
     */
    public const DEFAULT_MAP_CITY = 'address.locality';
    /**
     * @var string
     */
    public const MAP_STATE = 'amState';
    /**
     * @var string
     */
    public const DEFAULT_MAP_STATE = 'address.region';
    /**
     * @var string
     */
    public const MAP_COUNTRY = 'amCountry';
    /**
     * @var string
     */
    public const DEFAULT_MAP_COUNTRY = 'address.country';

    // Standard OIDC Claims for dropdown selection in admin UI
    /**
     * @var mixed[]
     */
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
    /**
     * @var string
     */
    public const OAUTH_LOGIN_URL = 'm2oidc/actions/sendAuthorizationRequest';

    //images
    /**
     * @var string
     */
    public const IMAGE_RIGHT = 'right.png';
    /**
     * @var string
     */
    public const IMAGE_WRONG = 'wrong.png';

    /**
     * @var string
     */
    public const CALLBACK_URL = 'm2oidc/actions/ReadAuthorizationResponse';
    /**
     * @var string
     */
    public const CODE = 'code';
    /**
     * @var string
     */
    public const GRANT_TYPE = 'authorization_code';

    // PKCE (RFC 7636) — FEAT-01
    /**
     * @var string
     */
    public const PKCE_METHOD_S256 = 'S256';
    /**
     * @var string
     */
    public const PKCE_METHOD_PLAIN = 'plain';

    //OAUTH Constants
    /**
     * @var string
     */
    public const OAUTH = 'OAUTH';
    /**
     * @var string
     */
    public const HTTP_REDIRECT = 'HttpRedirect';

    //Registration Status
    /**
     * @var string
     */
    public const STATUS_VERIFY_LOGIN = "M2OIDC_VERIFY_CUSTOMER";
    /**
     * @var string
     */
    public const STATUS_COMPLETE_LOGIN = "M2OIDC_VERIFIED";

    //plugin constants
    /**
     * @var string
     */
    public const VERSION = "v4.2.0";
    /**
     * @var string
     */
    public const MAGENTO_COUNTER = "magento_count";

    //Debug log
    /**
     * @var string
     */
    public const ENABLE_DEBUG_LOG = 'debug_log';
    /**
     * @var string
     */
    public const LOG_FILE_TIME = 'log_file_time';
    /**
     * @var string
     */
    public const SEND_EMAIL = 'send_email';
    /**
     * @var string
     */
    public const ADMINEMAIL = 'admin_email';
    /**
     * @var string
     */
    public const CUSTOMER_EMAIL = 'email';
}
