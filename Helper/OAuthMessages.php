<?php

namespace MiniOrange\OAuth\Helper;

/**
 * This class lists down all of our messages to be shown to the admin or
 * in the frontend. This is a constant file listing down all of our
 * constants. Has a parse function to parse and replace any dynamic
 * values needed to be inputed in the string. Key is usually of the form
 * {{key}}
 */
class OAuthMessages
{
    //Registration Flow Messages
    const REQUIRED_REGISTRATION_FIELDS    = 'Email, CompanyName, Password and Confirm Password are required fields. Please enter valid entries.';
    const INVALID_PASS_STRENGTH           = 'Choose a password with minimum length 6.';
    const PASS_MISMATCH                   = 'Passwords do not match.';
    const INVALID_EMAIL                   = 'Please match the format of Email. No special characters are allowed.';
    const ACCOUNT_EXISTS                  = 'You already have an account with miniOrange. Please enter a valid password.';
    const TRANSACTION_LIMIT_EXCEEDED      = 'You have reached the maximum transaction limit';
    const ERROR_PHONE_FORMAT              = '{{phone}} is not a valid phone number. Please enter a valid Phone Number. E.g:+1XXXXXXXXXX';

    const REG_SUCCESS                     = 'Your account has been retrieved successfully.';
    const NEW_REG_SUCCES                  = 'Registration complete!';

    //Validation Flow Messages
    const INVALID_CRED                    = 'Invalid username or password. Please try again.';

    //General Flow Messages
    const REQUIRED_FIELDS                 = 'Please fill in the required fields.';
    const ERROR_OCCURRED                  = 'An error occured while processing your request. Please try again.';
    const NOT_REG_ERROR                   = 'Please register and verify your account before trying to configure your settings. Go the Account 
                                            Section to complete your registration registered.';
    const INVALID_OP                      = 'Invalid Operation. Please Try Again.';
    const INVALID_REG                     = "Incomplete Details or Session Expired. Please Register again.";

    //Licensing Messages
    const INVALID_LICENSE                 = 'License key for this instance is incorrect. Make sure you have not tampered with it at all. 
                                            Please enter a valid license key.';
    const LICENSE_KEY_IN_USE              = 'License key you have entered has already been used. Please enter a key which has not been used 
                                            before on any other instance or if you have exausted all your keys then contact us at 
                                            info@xecurify.com to buy more keys.';
    const ENTERED_INVALID_KEY             = 'You have entered an invalid license key. Please enter a valid license key.';
    const LICENSE_VERIFIED                = 'Your license is verified. You can now setup the plugin.';
    const NOT_UPGRADED_YET                = 'You have not upgraded yet. <a href="{{url}}">Click here</a> to upgrade to premium version.';

    //cURL Error
    const CURL_ERROR                      = 'ERROR: <a href="http://php.net/manual/en/curl.installation.php" target="_blank">PHP cURL extension</a> 
                                            is not installed or disabled. Query submit failed.';

    //Query Form Error
    const REQUIRED_QUERY_FIELDS           = 'Please fill up Email and Query fields to submit your query.';
    const ERROR_QUERY                     = 'Your query could not be submitted. Please try again.';
    const QUERY_SENT                      = 'Thanks for getting in touch! We shall get back to you shortly.';

    //Save Settings Error
    const NO_IDP_CONFIG                   = 'Please Configure an Identity Provider.';

    const SETTINGS_SAVED                  = 'Settings saved successfully.';
    const IDP_DELETED                     = 'Identity Provider settings deleted successfully.';
    const SP_ENTITY_ID_CHANGED            = 'SP Entity ID changed successfully.';
    const SP_ENTITY_ID_NULL               = 'SP EntityID/Issuer cannot be NULL.';

    const INVALID_USER_INFO               = 'Error returned from Get User Info Endpoint from the OAuth Provider';
    
    const EMAIL_ATTRIBUTE_NOT_RETURNED    = 'Email address not received.';
    const AUTO_CREATE_USER_LIMIT          = "Your Auto Create User Limit for the free Miniorange Magento OAuth/OpenID plugin is exceeded. Please Upgrade to any of the Premium Plan to continue the service.";
    const INVALID_EMAIL_FORMAT            = "This is not a valid email. please enter a valid email.";
    const AUTO_CREATE_USER_DISABLED       = "User does not exist and auto creation of users is disabled. Please contact your site administrator.";
    const AUTO_CREATE_ADMIN_DISABLED      = "Admin-Access declined. The admin user does not exist and auto creation of administrators is disabled. Please contact your site administrator.";
    const ADMIN_ACCOUNT_NOT_FOUND         = "Admin login failed: No administrator account exists for '{{email}}'. This login option is only available for users with existing admin accounts. Please contact your site administrator if you believe you should have admin access.";

    

    /**
     * Parse the message and replace the dynamic values with the
     * necessary values. The dynamic values needs to be passed in
     * the key value pair. Key is usually of the form {{key}}.
     *
     * @param $message
     * @param $data
     */
    public static function parse($message, $data = [])
    {
        $message = constant("self::".$message);
        foreach ($data as $key => $value) {
            $message = str_replace("{{" . $key . "}}", $value, $message);
        }
        return $message;
    }
}
