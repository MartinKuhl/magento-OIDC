<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class lists down constant values used all over our Module.
 */
class OAuthConstants
{
    const MODULE_DIR           = 'MiniOrange_OAuth';
    const MODULE_TITLE         = 'OAuth Client';

    //ACL Settings
    const MODULE_BASE          = '::OAuth';
    const MODULE_OAUTHSETTINGS = '::oauth_settings';
    const MODULE_SIGNIN        = '::signin_settings';
    const MODULE_ATTR          = '::attr_settings';
    const MODULE_FAQ           = '::faq_settings';
    const MODULE_ACCOUNT       = '::account_settings';
    const MODULE_SUPPORT       = '::support';
    const MODULE_UPGRADE       = '::upgrade';

    const MODULE_IMAGES        = '::images/';
    const MODULE_CERTS         = '::certs/';
    const MODULE_CSS           = '::css/';
    const MODULE_JS            = '::js/';

    // request option parameter values
    const LOGIN_ADMIN_OPT      = 'oauthLoginAdminUser';
    const TEST_CONFIG_OPT      = 'mooauth_test'; //'testConfig';
    const TEST_OPT             = 'mooauth_test';
    const TEST_OAUTH_OPT       = 'mooauth_test'; //should be removed later

    //database keys

    const APP_NAME             = 'appName';
    const CLIENT_ID            = 'clientID';
    const CLIENT_SECRET        = 'clientSecret';
    const SCOPE                = 'scope';
    const AUTHORIZE_URL        = 'authorizeURL';
    const ACCESSTOKEN_URL      = 'accessTokenURL';
    const GETUSERINFO_URL      = 'getUserInfoURL';
    const OAUTH_LOGOUT_URL     = 'oauthLogoutURL';

    const ID_TOKEN             = 'id_token';
    const TEST_RELAYSTATE      = 'testvalidate';
    const MAP_MAP_BY           = 'amAccountMatcher';
    const DEFAULT_MAP_BY       = 'email';
    const DEFAULT_GROUP        = 'General';
    const SEND_HEADER          = 'header';
    const SEND_BODY            = 'body';
    const ENDPOINT_URL         = 'endpoint_url';

    const NAME_ID              = 'nameId';
    const IDP_NAME             = 'identityProviderName';
    const X509CERT             = 'certificate';
    const JWKS_URL             = 'jwks_url';
    const RESPONSE_SIGNED      = 'responseSigned';
    const ASSERTION_SIGNED     = 'assertionSigned';
    const ISSUER               = 'samlIssuer';
    const DB_FIRSTNAME         = 'firstname';
    const USER_NAME            = 'username';
    const DB_LASTNAME          = 'lastname';
    const CUSTOMER_KEY         = 'customerKey';
    const CUSTOMER_EMAIL       = 'email';
    const CUSTOMER_PHONE       = 'phone';
    const CUSTOMER_NAME        = 'cname';
    const CUSTOMER_FNAME       = 'customerFirstName';
    const CUSTOMER_LNAME       = 'customerLastName';
    const SAMLSP_CKL           = 'ckl';
    const SAMLSP_LK            = 'lk';
    const SHOW_ADMIN_LINK      = 'showadminlink';
    const SHOW_CUSTOMER_LINK   = 'showcustomerlink';
    const REG_STATUS           = 'registrationStatus';
    const API_KEY              = 'apiKey';
    const TOKEN                = 'token';
    const BUTTON_TEXT          = 'buttonText';
    const IS_TEST              = 'isTest';

    // attribute mapping constants
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

    //URLs
    const OAUTH_LOGIN_URL      = 'mooauth/actions/sendAuthorizationRequest';

    //images
    const IMAGE_RIGHT          = 'right.png';
    const IMAGE_WRONG          = 'wrong.png';

    const TXT_ID               = 'miniorange/oauth/transactionID';
    const CALLBACK_URL         = 'mooauth/actions/ReadAuthorizationResponse';
    const CODE                 = 'code';
    const GRANT_TYPE           = 'authorization_code';

    //OAUTH Constants
    const OAUTH                = 'OAUTH';
    const HTTP_REDIRECT        = 'HttpRedirect';

    //Registration Status
    const STATUS_VERIFY_LOGIN  = "MO_VERIFY_CUSTOMER";
    const STATUS_COMPLETE_LOGIN = "MO_VERIFIED";

    //plugin constants
    const DEFAULT_CUSTOMER_KEY = "xxxxx16555";
    const DEFAULT_API_KEY      = "xxxxxfFd2XcvTGDemZvbw1bcUesNJWEqKbbUq";
    const SAMLSP_KEY           = 'customerKey';
    const VERSION              = "v4.2.0";
    const HOSTNAME                 = "https://login.xecurify.comx";
    const PLUGIN_PORTAL_HOSTNAME   = "https://magento.shanekatear.inx/plugin-portal";
    const AREA_OF_INTEREST         = 'Magento 2.0 OAuth Client Plugin';
    const MAGENTO_COUNTER          = "magento_count";
    //Debug log
    const ENABLE_DEBUG_LOG         = 'debug_log';
    const LOG_FILE_TIME            = 'log_file_time';
    const SEND_EMAIL               = 'send_email';
    const SEND_EMAIL_CORE_CONFIG_DATA = 'send_email_config_data';
    const ADMINEMAIL               = 'admin_email';
    const PREVIOUS_DATE            = 'previous_date';
    const DATA_ADDED               = 'data_added';
    const PLUGIN_VERSION           = 'v4.2.0';
    const TIME_STAMP               = 'time_stamp';
    const TEST_CONFIGURATION_OPT   = 'testConfig';
}
