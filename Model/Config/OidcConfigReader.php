<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use M2Oidc\OAuth\Model\Provider\ProviderResolver;

/**
 * Provider-aware configuration reader for M2Oidc OAuth module.
 *
 * Extracted from OAuthUtility to give a focused, injectable config API.
 * Provider-specific keys are resolved from m2oidc_oauth_client_apps;
 * global keys fall back to core_config_data via ScopeConfigInterface.
 */
class OidcConfigReader
{
    /**
     * Maps OAuthConstants config keys to m2oidc_oauth_client_apps column names.
     *
     * Keys listed here are provider-specific and will be read from the app table.
     * Keys NOT listed here are global and always read from core_config_data
     * (e.g. ENABLE_DEBUG_LOG, IS_TEST, LOG_FILE_TIME).
     */
    public const CONFIG_TO_COLUMN = [
        // App / Endpoints
        'appName'                       => 'app_name',
        'clientID'                      => 'clientID',
        'clientSecret'                  => 'client_secret',
        'scope'                         => 'scope',
        'authorizeURL'                  => 'authorize_endpoint',
        'accessTokenURL'                => 'access_token_endpoint',
        'getUserInfoURL'                => 'user_info_endpoint',
        'oauthLogoutURL'                => 'endsession_endpoint',
        'endpoint_url'                  => 'well_known_config_url',
        'jwks_url'                      => 'jwks_endpoint',
        'samlIssuer'                    => 'issuer',

        // Send flags
        'header'                        => 'values_in_header',
        'body'                          => 'values_in_body',

        // Visibility / behaviour flags
        'showadminlink'                 => 'show_admin_link',
        'showcustomerlink'              => 'show_customer_link',
        'autoCreateAdmin'               => 'm2oidc_auto_create_admin',
        'autoCreateCustomer'            => 'm2oidc_auto_create_customer',
        'enableLoginRedirect'           => 'autoredirect',
        'buttonText'                    => 'button_label',
        'disableNonOidcAdminLogin'      => 'm2oidc_disable_non_oidc_admin_login',
        'disableNonOidcCustomerLogin'   => 'm2oidc_disable_non_oidc_customer_login',

        // Attribute mappings
        'amEmail'                       => 'email_attribute',
        'amUsername'                    => 'username_attribute',
        'amFirstName'                   => 'firstname_attribute',
        'amLastName'                    => 'lastname_attribute',
        'group'                         => 'group_attribute',
        'defaultRole'                   => 'default_role',
        'amDob'                         => 'dob_attribute',
        'amGender'                      => 'gender_attribute',
        'amPhone'                       => 'billing_phone_attribute',
        'amStreet'                      => 'billing_address_attribute',
        'amZip'                         => 'billing_zip_attribute',
        'amCity'                        => 'billing_city_attribute',
        'amState'                       => 'billing_state_attribute',
        'amCountry'                     => 'billing_country_attribute',

        // Role / group mapping
        'adminRoleMapping'              => 'oauth_admin_role_mapping',
        'amAccountMatcher'              => 'm2oidc_create_user_in_magento_by_using',
        'unlistedRole'                  => 'roles_mapped',
        'createUserIfRoleNotMapped'     => 'm2oidc_dont_create_user_if_role_not_mapped',

        // Customer Group mapping
        'customerGroupMapping'           => 'oauth_customer_group_mapping',
        'defaultCustomerGroup'           => 'default_group',
        'createCustomerIfGroupNotMapped' => 'm2oidc_dont_create_customer_if_group_not_mapped',
        'updateFrontendGroupsOnSso'      => 'update_frontend_groups_on_sso',

        // IdP-Initiated SSO (OIDC Third-Party Initiated Login §4)
        'idpInitiatedEnabled'            => 'idp_initiated_enabled',

        // Profile / address / role sync flags
        'sync_customer_profile_on_sso'   => 'sync_customer_profile_on_sso',
        'sync_customer_address_on_sso'   => 'sync_customer_address_on_sso',
        'sync_customer_group_on_sso'     => 'sync_customer_group_on_sso',
        'sync_admin_profile_on_sso'      => 'sync_admin_profile_on_sso',
        'sync_admin_role_on_sso'         => 'sync_admin_role_on_sso',

        // Claim value encoding
        'claimEncoding'                  => 'claim_encoding',
    ];

    /**
     * @param ProviderResolver     $providerResolver For resolving the active provider row
     * @param ScopeConfigInterface $scopeConfig      For global key fallback via core_config_data
     */
    public function __construct(
        private readonly ProviderResolver $providerResolver,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Read a config value — provider-specific keys from the app table, global keys from core_config_data.
     *
     * Provider-specific keys are read EXCLUSIVELY from the
     * m2oidc_oauth_client_apps table. No fallback to core_config_data.
     * Returns null if the column is empty or the provider is not found.
     *
     * @param string $config OAuthConstants key (e.g. OAuthConstants::MAP_EMAIL)
     */
    public function getStoreConfig(string $config): mixed
    {
        if (isset(self::CONFIG_TO_COLUMN[$config])) {
            $provider = $this->providerResolver->resolveActiveProvider();
            $column   = self::CONFIG_TO_COLUMN[$config];

            if ($provider !== [] && array_key_exists($column, $provider)) {
                $value = $provider[$column];
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            // No fallback — provider-specific values live exclusively in the app table
            return null;
        }

        // Global key — always read from core_config_data
        return $this->scopeConfig->getValue(
            'm2oidc/oauth/' . $config,
            ScopeInterface::SCOPE_STORE
        );
    }
}
