<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class lists down constant values used all over our Module.
 */
class OAuthConstants
{
    const MODULE_DIR           = 'MiniOrange_OAuth';
    const MODULE_TITLE         = 'Authelia OIDC';

    //ACL Settings
    const MODULE_OAUTHSETTINGS = '::oauth_settings';
    const MODULE_SIGNIN        = '::signin_settings';
    const MODULE_ATTR          = '::attr_settings';

    const MODULE_IMAGES        = '::images/';
    const MODULE_CERTS         = '::certs/';
    const MODULE_CSS           = '::css/';
    const MODULE_JS            = '::js/';

    // request option parameter values
    const LOGIN_ADMIN_OPT      = 'oauthLoginAdminUser';
    const TEST_CONFIG_OPT      = 'mooauth_test';

    //database keys

    const APP_NAME             = 'appName';
    const CLIENT_ID            = 'clientID';
    const CLIENT_SECRET        = 'clientSecret';
    const SCOPE                = 'scope';
    const AUTHORIZE_URL        = 'authorizeURL';
    const ACCESSTOKEN_URL      = 'accessTokenURL';
    const GETUSERINFO_URL      = 'getUserInfoURL';
    const OAUTH_LOGOUT_URL     = 'oauthLogoutURL';

    const TEST_RELAYSTATE      = 'testvalidate';
    const MAP_MAP_BY           = 'amAccountMatcher';
    const DEFAULT_MAP_BY       = 'email';
    const DEFAULT_GROUP        = 'General';
    const SEND_HEADER          = 'header';
    const SEND_BODY            = 'body';
    const ENDPOINT_URL         = 'endpoint_url';

    const X509CERT             = 'certificate';
    const JWKS_URL             = 'jwks_url';
    const ISSUER               = 'samlIssuer';
    const DB_FIRSTNAME         = 'firstname';
    const USER_NAME            = 'username';
    const DB_LASTNAME          = 'lastname';
    const SHOW_ADMIN_LINK      = 'showadminlink';
    const SHOW_CUSTOMER_LINK   = 'showcustomerlink';
    const BUTTON_TEXT          = 'buttonText';
    const IS_TEST              = 'isTest';
    const AUTO_CREATE_ADMIN    = 'autoCreateAdmin';
    const AUTO_CREATE_CUSTOMER = 'autoCreateCustomer';
    const ENABLE_LOGIN_REDIRECT= 'enableLoginRedirect';

    // Login type constants for differentiating admin vs customer OIDC login
    const LOGIN_TYPE_CUSTOMER  = 'customer';
    const LOGIN_TYPE_ADMIN     = 'admin';

    // attribute mapping constants
    // ggf. extend
    const MAP_EMAIL            = 'amEmail';
    const DEFAULT_MAP_EMAIL    = 'email';
    const MAP_USERNAME         = 'amUsername';
    const DEFAULT_MAP_USERN    = 'username';
    const MAP_FIRSTNAME        = 'amFirstName';
    const DEFAULT_MAP_FN       = 'firstName';
    const DEFAULT_MAP_LN       = 'lastName';
    const MAP_LASTNAME         = 'amLastName';
    const MAP_DEFAULT_ROLE     = 'defaultRole';
    const DEFAULT_ROLE         = 'General';
    const MAP_GROUP            = 'group';
    const UNLISTED_ROLE        = 'unlistedRole';
    const CREATEIFNOTMAP       = 'createUserIfRoleNotMapped';

    // Customer data attribute mapping constants
    const MAP_DOB              = 'amDob';
    const DEFAULT_MAP_DOB      = 'birthdate';
    const MAP_GENDER           = 'amGender';
    const DEFAULT_MAP_GENDER   = 'gender';
    const MAP_PHONE            = 'amPhone';
    const DEFAULT_MAP_PHONE    = 'phone_number';
    const MAP_STREET           = 'amStreet';
    const DEFAULT_MAP_STREET   = 'address.street_address';
    const MAP_ZIP              = 'amZip';
    const DEFAULT_MAP_ZIP      = 'address.postal_code';
    const MAP_CITY             = 'amCity';
    const DEFAULT_MAP_CITY     = 'address.locality';
    const MAP_STATE            = 'amState';
    const DEFAULT_MAP_STATE    = 'address.region';
    const MAP_COUNTRY          = 'amCountry';
    const DEFAULT_MAP_COUNTRY  = 'address.country';

    // Standard OIDC Claims for dropdown selection in admin UI
    const OIDC_STANDARD_CLAIMS = [
        'sub', 'name', 'given_name', 'family_name', 'middle_name',
        'nickname', 'preferred_username', 'profile', 'picture', 'website',
        'email', 'email_verified', 'gender', 'birthdate', 'zoneinfo',
        'locale', 'phone_number', 'phone_number_verified', 'updated_at',
        'address.formatted', 'address.street_address', 'address.locality',
        'address.region', 'address.postal_code', 'address.country'
    ];

    // Stores received OIDC claims from Test Configuration
    const RECEIVED_OIDC_CLAIMS = 'receivedOidcClaims';

    //URLs
    const OAUTH_LOGIN_URL      = 'mooauth/actions/sendAuthorizationRequest';

    //images
    const IMAGE_RIGHT          = 'right.png';
    const IMAGE_WRONG          = 'wrong.png';

    const CALLBACK_URL         = 'mooauth/actions/ReadAuthorizationResponse';
    const CODE                 = 'code';
    const GRANT_TYPE           = 'authorization_code';

    //OAUTH Constants
    const OAUTH                = 'OAUTH';
    const HTTP_REDIRECT        = 'HttpRedirect';

    //Registration Status
    const STATUS_VERIFY_LOGIN      = "MO_VERIFY_CUSTOMER";
    const STATUS_COMPLETE_LOGIN    = "MO_VERIFIED";

    //plugin constants
    const VERSION                  = "v4.2.0";
    const MAGENTO_COUNTER          = "magento_count";

    //Debug log
    const ENABLE_DEBUG_LOG         = 'debug_log';
    const LOG_FILE_TIME            = 'log_file_time';
    const SEND_EMAIL               = 'send_email';
    const SEND_EMAIL_CORE_CONFIG_DATA = 'send_email_config_data';
    const ADMINEMAIL               = 'admin_email';
    const DATA_ADDED               = 'data_added';
    const PLUGIN_VERSION           = 'v4.2.0';
    const TIME_STAMP               = 'time_stamp';
    const CUSTOMER_EMAIL           = 'email';

}