<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Helper;

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
    public const REQUIRED_REGISTRATION_FIELDS = 'Email, CompanyName, Password and Confirm Password are required fields.'
        . ' Please enter valid entries.';
    public const INVALID_PASS_STRENGTH = 'Choose a password with minimum length 6.';
    public const PASS_MISMATCH = 'Passwords do not match.';
    public const INVALID_EMAIL = 'Please match the format of Email.'
        . ' No special characters are allowed.';
    public const ACCOUNT_EXISTS = 'You already have an account with m2Oidc.'
        . ' Please enter a valid password.';
    public const TRANSACTION_LIMIT_EXCEEDED = 'You have reached the maximum transaction limit';
    public const ERROR_PHONE_FORMAT = '{{phone}} is not a valid phone number.'
        . ' Please enter a valid Phone Number. E.g:+1XXXXXXXXXX';

    public const REG_SUCCESS = 'Your account has been retrieved successfully.';
    public const NEW_REG_SUCCES = 'Registration complete!';

    //Validation Flow Messages
    public const INVALID_CRED = 'Invalid username or password. Please try again.';

    //General Flow Messages
    public const REQUIRED_FIELDS = 'Please fill in the required fields.';
    public const ERROR_OCCURRED = 'An error occured while processing your request. Please try again.';
    public const NOT_REG_ERROR = 'Please register and verify your account before trying to configure your settings.'
        . ' Go the Account Section to complete your registration registered.';
    public const INVALID_OP = 'Invalid Operation. Please Try Again.';
    public const INVALID_REG = "Incomplete Details or Session Expired. Please Register again.";

    //Licensing Messages
    public const INVALID_LICENSE = 'License key for this instance is incorrect. Make sure you have not'
        . ' tampered with it at all. Please enter a valid license key.';
    public const LICENSE_KEY_IN_USE = 'License key you have entered has already been used. Please enter a key'
        . ' which has not been used before on any other instance or if you have exausted all your keys then'
        . ' contact us at info@xecurify.com to buy more keys.';
    public const ENTERED_INVALID_KEY = 'You have entered an invalid license key. Please enter a valid license key.';
    public const LICENSE_VERIFIED = 'Your license is verified. You can now setup the plugin.';
    public const NOT_UPGRADED_YET = 'You have not upgraded yet. <a href="{{url}}">Click here</a>'
        . ' to upgrade to premium version.';

    //cURL Error
    public const CURL_ERROR = 'ERROR: <a href="http://php.net/manual/en/curl.installation.php" target="_blank">'
        . 'PHP cURL extension</a> is not installed or disabled. Query submit failed.';

    //Query Form Error
    public const REQUIRED_QUERY_FIELDS = 'Please fill up Email and Query fields to submit your query.';
    public const ERROR_QUERY = 'Your query could not be submitted. Please try again.';
    public const QUERY_SENT = 'Thanks for getting in touch! We shall get back to you shortly.';

    //Save Settings Error
    public const NO_IDP_CONFIG = 'Please Configure an Identity Provider.';

    public const SETTINGS_SAVED = 'Settings saved successfully.';
    public const IDP_DELETED = 'Identity Provider settings deleted successfully.';
    public const SP_ENTITY_ID_CHANGED = 'SP Entity ID changed successfully.';
    public const SP_ENTITY_ID_NULL = 'SP EntityID/Issuer cannot be NULL.';

    public const INVALID_USER_INFO = 'Error returned from Get User Info Endpoint from the OAuth Provider';

    public const EMAIL_ATTRIBUTE_NOT_RETURNED = 'Email address not received.';
    public const AUTO_CREATE_USER_LIMIT = "Your Auto Create User Limit for the free Miniorange Magento"
        . " OAuth/OpenID plugin is exceeded. Please Upgrade to any of the Premium Plan to continue the service.";
    public const INVALID_EMAIL_FORMAT = "This is not a valid email. please enter a valid email.";
    public const AUTO_CREATE_USER_DISABLED = "User does not exist and auto creation of users is disabled."
        . " Please contact your site administrator.";
    public const AUTO_CREATE_ADMIN_DISABLED = "Admin-Access declined. The admin user does not exist and auto"
        . " creation of administrators is disabled. Please contact your site administrator.";
    public const ADMIN_ACCOUNT_NOT_FOUND = "Admin login failed: No administrator account exists for '{{email}}'."
        . " This login option is only available for users with existing admin accounts. Please contact your site"
        . " administrator if you believe you should have admin access.";

    // -------------------------------------------------------------------------
    // Attribute mapping errors
    // -------------------------------------------------------------------------

    /** The IdP did not return an email claim. Lists the received claims so the admin can identify the correct name. */
    public const EMAIL_CLAIM_NOT_FOUND_WITH_CONTEXT
        = "OIDC provider returned no email claim. Received claims: {{received_claims}}."
        . " Check that your Email Attribute setting (currently '{{configured_attribute}}')"
        . " matches the actual claim name (case-sensitive).";

    /** The IdP returned the email under a differently-cased claim name. */
    public const EMAIL_CLAIM_WRONG_CASE
        = "OIDC provider returned claim '{{actual_claim}}' but Email Attribute is set to '{{configured_claim}}'."
        . " Update the Email Attribute field to '{{actual_claim}}'.";

    // -------------------------------------------------------------------------
    // Role mapping errors
    // -------------------------------------------------------------------------

    /** No admin role could be mapped for the OIDC groups the user belongs to. */
    public const ADMIN_ROLE_MAPPING_NO_MATCH
        = "Admin role mapping failed: no role configured for OIDC groups [{{groups}}]."
        . " Configure a role mapping in Attribute Settings, or set a Default Admin Role as fallback."
        . " Admin user creation aborted.";

    /** A configured role mapping references a Magento role ID that does not exist. */
    public const ADMIN_ROLE_MAPPING_INVALID_ROLE_ID
        = "Admin role mapping failed: configured role ID '{{role_id}}' does not exist in Magento."
        . " Verify the role ID in the Attribute Settings > Admin Role Mapping table.";

    // -------------------------------------------------------------------------
    // JWT errors
    // -------------------------------------------------------------------------

    /** JWT signature verification failed (includes JWKS URL for diagnosis). */
    public const JWT_SIGNATURE_INVALID
        = "JWT signature verification failed for issuer '{{issuer}}'."
        . " Check that the JWKS URI is correct and the IdP is signing with RS256, RS384, or RS512.";

    /** JWT has expired. */
    public const JWT_EXPIRED
        = "ID token has expired (exp: {{exp}}, server time: {{now}})."
        . " Check server clock synchronisation between Magento and the IdP.";

    /** JWT issuer does not match the configured value. */
    public const JWT_ISSUER_MISMATCH
        = "JWT issuer mismatch: token has '{{token_issuer}}' but expected '{{configured_issuer}}'."
        . " Update the Issuer field in OAuth Settings to '{{token_issuer}}'.";

    // -------------------------------------------------------------------------
    // User creation errors (more specific replacements for generic messages)
    // -------------------------------------------------------------------------

    /** Auto-create is disabled and the admin user does not exist. */
    public const ADMIN_AUTO_CREATE_DISABLED_FOR_EMAIL
        = "Admin account not found for '{{email}}'."
        . " Auto-creation is disabled. Create the admin account manually or enable"
        . " 'Auto Create Admin Users' in Sign In Settings.";

    /** Login rejected because the account was created by a different IdP. */
    public const PROVIDER_MISMATCH
        = "Login failed for '{{email}}': this account was created with a different identity provider."
        . " Please use the same login method you originally registered with.";

    /** Auto-create is disabled and the customer does not exist. */
    public const CUSTOMER_AUTO_CREATE_DISABLED_FOR_EMAIL
        = "Account not found for '{{email}}'."
        . " Auto-creation is disabled. Please register first or contact the store administrator.";

    // -------------------------------------------------------------------------
    // Customer group mapping errors
    // -------------------------------------------------------------------------

    /** Customer creation denied because the user's OIDC groups have no matching customer group mapping. */
    public const CUSTOMER_GROUP_MAPPING_NO_MATCH
        = "Customer creation denied: OIDC groups [{{groups}}] are not mapped to any Magento customer group."
        . " Configure customer group mappings in Attribute Settings, or set a Default Customer Group.";

    // -------------------------------------------------------------------------
    // Missing attribute errors (context-aware)
    // -------------------------------------------------------------------------

    /** Required OIDC claims were absent from the authentication response. */
    public const MISSING_ATTRIBUTES_DETAIL
        = "Authentication failed: received claims [{{received_claims}}] do not include"
        . " the required attribute(s): {{missing_attributes}}."
        . " Verify the Email Attribute and other configured mappings match the actual OIDC claim names.";

    /**
     * Parse the message and replace the dynamic values with the
     * necessary values. The dynamic values needs to be passed in
     * the key value pair. Key is usually of the form {{key}}.
     *
     * @param array<string, mixed> $data
     */
    // phpcs:ignore Magento2.Functions.StaticFunction
    public static function parse(string $message, array $data = []): string
    {
        $message = constant("self::" . $message);
        foreach ($data as $key => $value) {
            $strValue = is_array($value) ? implode('', $value) : (string) $value;
            $replaced = str_replace("{{" . $key . "}}", $strValue, (string) $message);
            $message = $replaced;
        }
        return (string) $message;
    }
}
