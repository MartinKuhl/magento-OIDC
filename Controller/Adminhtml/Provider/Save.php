<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Model\M2oidcOauthClientApps;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;
use M2Oidc\OAuth\Model\Validation\ProviderDataValidator;
use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;

/**
 * Admin controller — Save OIDC Provider.
 *
 * Route: POST /admin/m2oidc/provider/save
 *
 * Handles both INSERT (id=0) and UPDATE (id>0). Validates the CSRF form
 * key automatically through Magento's CSRF validation layer.
 */
class Save extends Action implements HttpPostActionInterface
{
    /**
     * @var string
     */
    public const ADMIN_RESOURCE = 'M2Oidc_OAuth::oauth_settings';

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var DataPersistorInterface */
    private readonly DataPersistorInterface $dataPersistor;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /** @var Curl */
    private readonly Curl $curl;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /** @var CacheInterface */
    private readonly CacheInterface $cache;

    /** @var ProviderDataValidator */
    private readonly ProviderDataValidator $providerDataValidator;

    /** @var SsrfUrlValidator */
    private readonly SsrfUrlValidator $ssrfUrlValidator;

    /**
     * Initialize provider save controller.
     *
     * @param Context                       $context
     * @param M2oidcOauthClientAppsFactory  $clientAppsFactory
     * @param AppResource                   $appResource
     * @param DataPersistorInterface        $dataPersistor
     * @param MappingRepository             $mappingRepository
     * @param Curl                          $curl
     * @param EncryptorInterface            $encryptor
     * @param CacheInterface                $cache
     * @param ProviderDataValidator         $providerDataValidator
     * @param SsrfUrlValidator              $ssrfUrlValidator
     */
    public function __construct(
        Context $context,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        DataPersistorInterface $dataPersistor,
        MappingRepository $mappingRepository,
        Curl $curl,
        EncryptorInterface $encryptor,
        CacheInterface $cache,
        ProviderDataValidator $providerDataValidator,
        SsrfUrlValidator $ssrfUrlValidator
    ) {
        $this->clientAppsFactory     = $clientAppsFactory;
        $this->appResource           = $appResource;
        $this->dataPersistor         = $dataPersistor;
        $this->mappingRepository     = $mappingRepository;
        $this->curl                  = $curl;
        $this->cache                 = $cache;
        $this->encryptor             = $encryptor;
        $this->providerDataValidator = $providerDataValidator;
        $this->ssrfUrlValidator      = $ssrfUrlValidator;
        parent::__construct($context);
    }

    /**
     * Process the provider form POST.
     */
    #[\Override]
    public function execute(): Redirect
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();
        $data = $request->getPostValue();
        if (!$data) {
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $providerId = (int) ($data['id'] ?? 0);
        $redirect   = $this->resultRedirectFactory->create();

        try {
            $model = $this->clientAppsFactory->create();

            if ($providerId > 0) {
                $this->appResource->load($model, $providerId);
                if (!$model->getId()) {
                    $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                    return $redirect->setPath('*/*/index');
                }
            }

            // Validate required attribute mapping fields
            $requiredAttributeFields = [
                'email_attribute'     => (string) __('Email Claim'),
                'username_attribute'  => (string) __('Username Claim'),
                'firstname_attribute' => (string) __('First Name Claim'),
                'lastname_attribute'  => (string) __('Last Name Claim'),
            ];
            $missingFields = [];
            foreach ($requiredAttributeFields as $field => $label) {
                if ($this->sanitizeString((string) ($data[$field] ?? '')) === '') {
                    $missingFields[] = $label;
                }
            }
            if ($missingFields !== []) {
                $this->messageManager->addErrorMessage(
                    (string) __(
                        'The following attribute mapping fields are required: %1',
                        implode(', ', $missingFields)
                    )
                );
                $this->dataPersistor->set('oidc_provider', $data);
                return $providerId > 0
                    ? $redirect->setPath('*/*/edit', ['id' => $providerId])
                    : $redirect->setPath('*/*/edit');
            }

            // Lockout-prevention: OIDC-only requires the SSO button to be shown.
            // isset() is intentional here — we check POST presence, not the value.
            if (!isset($data['show_admin_link']) && isset($data['m2oidc_disable_non_oidc_admin_login'])) {
                $data['m2oidc_disable_non_oidc_admin_login'] = 0;
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Admin OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the admin login page.'
                    )
                );
            }

            if (!isset($data['show_customer_link']) && isset($data['m2oidc_disable_non_oidc_customer_login'])) {
                $data['m2oidc_disable_non_oidc_customer_login'] = 0;
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Customer OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the customer login page.'
                    )
                );
            }

            // Shared validation — enum whitelists, SSRF-safe endpoint URLs, and
            // the "no OIDC users yet" lockout guard are enforced by the validator.
            $validation = $this->providerDataValidator->validate($data, $providerId);
            foreach ($validation->getWarnings() as $validationWarning) {
                $this->messageManager->addWarningMessage($validationWarning);
            }
            $data = $validation->getData();

            // Sanitize and apply multi-provider fields
            $model->setData('app_name', $this->sanitizeString($data['app_name'] ?? ''));
            $model->setData('display_name', $this->sanitizeString($data['display_name'] ?? ''));
            $model->setData('is_active', (int) ($data['is_active'] ?? 0));
            $model->setData('login_type', (string) ($data['login_type'] ?? 'customer'));
            $model->setData('sort_order', max(0, (int) ($data['sort_order'] ?? 0)));
            $model->setData(
                'health_alert_failure_threshold',
                max(0, min(20, (int) ($data['health_alert_failure_threshold'] ?? 0)))
            );
            $model->setData('button_label', $this->sanitizeString($data['button_label'] ?? ''));
            $model->setData('button_color', $this->validateHexColor($data['button_color'] ?? ''));
            // Per-provider log file suffix: restrict to safe characters to prevent path traversal
            $rawSuffix = (string) ($data['log_file_suffix'] ?? '');
            $model->setData('log_file_suffix', preg_replace('/[^a-zA-Z0-9_-]/', '', $rawSuffix) ?: null);

            // Core endpoint and OAuth fields
            foreach ([
                'clientID', 'scope', 'grant_type',
                'authorize_endpoint', 'access_token_endpoint',
                'user_info_endpoint', 'jwks_endpoint', 'well_known_config_url',
                'endsession_endpoint', 'revocation_endpoint', 'issuer', 'post_logout_url',
                // Attribute mapping — basic claims
                'email_attribute', 'username_attribute',
                'firstname_attribute', 'lastname_attribute', 'group_attribute',
                // Attribute mapping — customer data
                'dob_attribute', 'gender_attribute',
                'billing_address_attribute', 'billing_zip_attribute',
                'billing_city_attribute', 'billing_state_attribute',
                'billing_country_attribute', 'billing_phone_attribute',
            ] as $field) {
                if (isset($data[$field])) {
                    $model->setData($field, $this->sanitizeString($data[$field]));
                }
            }

            // Claim value encoding — whitelist-validated by ProviderDataValidator.
            $model->setData('claim_encoding', (string) ($data['claim_encoding'] ?? 'none'));

            // Checkbox/Toggle fields.
            //
            // Each checkbox in the template is preceded by a hidden field with value="0":
            //   <input type="hidden"   name="show_admin_link" value="0">
            //   <input type="checkbox" name="show_admin_link" value="1">
            //
            // This means $data[$field] is ALWAYS set ("0" or "1").
            // Using isset() ? 1 : 0 would therefore always yield 1 — regardless of
            // whether the checkbox is checked. We cast the actual value instead.
            foreach ([
                'values_in_header',
                'values_in_body',
                // Login options
                'show_admin_link',
                'show_customer_link',
                'm2oidc_auto_create_admin',
                'm2oidc_auto_create_customer',
                'autoredirect_admin',    // Auto-redirect for admin login page
                'autoredirect_customer', // Auto-redirect for customer login page
                'm2oidc_disable_non_oidc_admin_login',
                'm2oidc_disable_non_oidc_customer_login',
                // Profile Sync on SSO Login
                'sync_customer_profile_on_sso',
                'sync_customer_address_on_sso',
                'sync_customer_group_on_sso',
                'sync_admin_profile_on_sso',
                'sync_admin_role_on_sso',
                // IdP-Initiated SSO
                'idp_initiated_enabled',
                // Public client (RFC 6749 §2.1): no client secret required/expected
                'public_client',
                // Health-check webhook alerting
                'health_alert_notify_on_recovery',
            ] as $field) {
                // FIX: use $field (not $checkbox) — variable name matches the foreach above.
                // (int) cast reads the actual "0"/"1" value sent by the hidden+checkbox pair.
                $model->setData($field, (int) ($data[$field] ?? 0));
            }

            // Default admin role
            $model->setData('default_role', $this->sanitizeString($data['default_role'] ?? ''));

            // Admin role mappings: oauth_role_mapping[N][group/role] → JSON → oauth_admin_role_mapping
            // $roleMappings stores the legacy format for JSON (read by getAdminRoleFromGroups()).
            // $roleMappingsNormalized uses the keys expected by replaceRoleMappings().
            $roleMappings           = [];
            $roleMappingsNormalized = [];
            if (!empty($data['oauth_role_mapping']) && is_array($data['oauth_role_mapping'])) {
                foreach ($data['oauth_role_mapping'] as $row) {
                    $group = $this->sanitizeString($row['group'] ?? '');
                    $role  = $this->sanitizeString($row['role'] ?? '');
                    if ($group !== '' && $role !== '') {
                        $roleMappings[]           = ['group' => $group, 'role' => $role];
                        $roleMappingsNormalized[] = ['oidc_group' => $group, 'magento_role_id' => $role];
                    }
                }
            }
            $model->setData('oauth_admin_role_mapping', json_encode($roleMappings));

            // Default customer group
            $model->setData('default_group', $this->sanitizeString($data['default_group'] ?? ''));

            // Customer group mappings: oauth_customer_group_mapping[N][group/customerGroup] → JSON
            // $cgMappings stores the legacy format for JSON.
            // $cgMappingsNormalized uses the keys expected by replaceRoleMappings().
            $cgMappings           = [];
            $cgMappingsNormalized = [];
            if (!empty($data['oauth_customer_group_mapping']) && is_array($data['oauth_customer_group_mapping'])) {
                foreach ($data['oauth_customer_group_mapping'] as $row) {
                    $group         = $this->sanitizeString($row['group'] ?? '');
                    $customerGroup = $this->sanitizeString($row['customerGroup'] ?? '');
                    if ($group !== '' && $customerGroup !== '') {
                        $cgMappings[]           = ['group' => $group, 'customerGroup' => $customerGroup];
                        $cgMappingsNormalized[] = ['oidc_group' => $group, 'magento_role_id' => $customerGroup];
                    }
                }
            }
            $model->setData('oauth_customer_group_mapping', json_encode($cgMappings));

            // Encrypt client secret only when a new value is provided; clear it entirely for public clients.
            if ((int) ($data['public_client'] ?? 0) === 1) {
                $model->setData('client_secret', '');
            } elseif (!empty($data['client_secret'])) {
                $model->setData('client_secret', $this->encryptor->encrypt($data['client_secret']));
            }

            // Health-check alert webhook URL: same "blank = keep existing" semantics as
            // client_secret above — it commonly embeds a bearer-token-equivalent secret in
            // its path/query (e.g. a Slack incoming-webhook URL), so it is encrypted at rest.
            if (!empty($data['health_alert_webhook_url'])) {
                $model->setData(
                    'health_alert_webhook_url',
                    $this->encryptor->encrypt($data['health_alert_webhook_url'])
                );
            }

            // PKCE method — whitelist-validated by ProviderDataValidator.
            $model->setData('pkce_flow', (string) ($data['pkce_flow'] ?? ''));

            // Require client_secret when creating a new confidential (non-public) client.
            // Public clients (RFC 6749 §2.1) have no secret by design — PKCE takes its role.
            if ($providerId === 0
                && (int) $model->getData('public_client') === 0
                && empty($data['client_secret'])
            ) {
                $this->messageManager->addErrorMessage(
                    (string) __(
                        'Client Secret is required for confidential clients. '
                        . 'For public clients (e.g. Zitadel, Authelia with public: true) '
                        . 'enable the "Public Client" option.'
                    )
                );
                $this->dataPersistor->set('oidc_provider', $data);
                return $redirect->setPath('*/*/edit');
            }

            // Auto-discover endpoints when a Discovery URL is provided.
            // Overrides any manually-entered endpoint fields, consistent with the form hint.
            $discoveryUrl = trim((string) ($data['well_known_config_url'] ?? ''));
            $discoverySucceeded = false;
            if ($discoveryUrl !== '') {
                $discovered = $this->performDiscovery($discoveryUrl);
                if ($discovered instanceof \stdClass) {
                    $this->applyDiscoveredEndpoints($discovered, $model, $providerId);
                    $discoverySucceeded = true;
                }
                // On failure, performDiscovery() already added an error message.
                // Save still proceeds so the user does not lose other field values.
            }

            // Capture old JWKS endpoint before save for cache invalidation
            $oldJwksEndpoint = (string) ($model->getOrigData('jwks_endpoint') ?? '');

            $this->appResource->save($model);

            // Invalidate stale JWKS cache when endpoint changes
            $newJwksEndpoint = (string) ($model->getData('jwks_endpoint') ?? '');
            if ($oldJwksEndpoint !== '' && $oldJwksEndpoint !== $newJwksEndpoint) {
                $this->cache->remove('m2oidc_jwks_' . hash('sha256', $oldJwksEndpoint));
            }

            // Phase 4 dual-write: mirror role/group mappings into normalized tables so
            // that service classes can read from the new schema while legacy columns
            // remain populated for backward compatibility.
            $savedId = (int) $model->getId();
            if ($savedId > 0) {
                $this->mappingRepository->replaceRoleMappings(
                    $savedId,
                    RoleMappingResource::TYPE_ADMIN_ROLE,
                    $roleMappingsNormalized
                );
                $this->mappingRepository->replaceRoleMappings(
                    $savedId,
                    RoleMappingResource::TYPE_CUSTOMER_GROUP,
                    $cgMappingsNormalized
                );

                // Phase 4: Save attribute sync settings from the dynamic rows UI
                $rawAttrMappings = $data['attr_mappings'] ?? [];
                if (is_array($rawAttrMappings)) {
                    $attrRows = [];
                    foreach ($rawAttrMappings as $attrRow) {
                        $type = trim((string) ($attrRow['attribute_type'] ?? ''));
                        $name = trim((string) ($attrRow['attribute_name'] ?? ''));
                        if ($type !== '' && $name !== '') {
                            $rawFn = trim((string) ($attrRow['transform_function'] ?? ''));
                            $rawPr = trim((string) ($attrRow['transform_params']   ?? ''));
                            $attrRows[] = [
                                'attribute_type'     => $type,
                                'attribute_name'     => $name,
                                'sync_on_sso'        => (int) ($attrRow['sync_on_sso'] ?? 0),
                                'transform_function' => $rawFn !== '' ? $rawFn : null,
                                'transform_params'   => $rawPr !== '' ? $rawPr : null,
                            ];
                        }
                    }
                    $this->mappingRepository->replaceAttributeMappings($savedId, $attrRows);
                }
            }

            if ($discoverySucceeded) {
                $this->messageManager->addSuccessMessage(
                    (string) __('Provider saved successfully. Endpoints auto-discovered from Discovery URL.')
                );
            } else {
                $this->messageManager->addSuccessMessage((string) __('Provider saved successfully.'));
            }
            $this->dataPersistor->clear('oidc_provider');

            if (isset($data['back']) && $data['back'] === 'test') {
                return $redirect->setPath('*/*/edit', [
                    'id'           => $model->getId(),
                    'pending_test' => '1',
                ]);
            }
            if (isset($data['back']) && $data['back'] === 'edit') {
                return $redirect->setPath('*/*/edit', ['id' => $model->getId()]);
            }
            return $redirect->setPath('*/*/index');

        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                (string) __('An error occurred while saving the provider: %1', $e->getMessage())
            );
            $this->dataPersistor->set('oidc_provider', $data);

            if ($providerId > 0) {
                return $redirect->setPath('*/*/edit', ['id' => $providerId]);
            }
            return $redirect->setPath('*/*/edit');
        }
    }

    /**
     * Apply auto-discovered OIDC endpoints from a discovery document to the provider model.
     *
     * Skips (and warns about) any discovered endpoint that is not HTTPS and
     * pre-enables the public-client toggle on CREATE when the IdP advertises 'none'
     * as a supported token-endpoint auth method.
     *
     * @param \stdClass              $discovered Parsed OIDC discovery document.
     * @param M2oidcOauthClientApps  $model      Provider model being saved.
     * @param int                    $providerId Provider ID from the form (0 on CREATE).
     */
    private function applyDiscoveredEndpoints(
        \stdClass $discovered,
        M2oidcOauthClientApps $model,
        int $providerId
    ): void {
        // Validate discovered endpoints are HTTPS
        $epKeys = [
            'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint',
            'jwks_uri', 'end_session_endpoint', 'revocation_endpoint',
        ];
        foreach ($epKeys as $epKey) {
            if (isset($discovered->$epKey) && is_string($discovered->$epKey)) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
                $epScheme = parse_url($discovered->$epKey, PHP_URL_SCHEME);
                if (strtolower((string) $epScheme) !== 'https') {
                    $this->messageManager->addWarningMessage(
                        (string) __('Discovered %1 is not HTTPS and was skipped.', $epKey)
                    );
                    unset($discovered->$epKey);
                }
            }
        }
        if (isset($discovered->authorization_endpoint)) {
            $model->setData('authorize_endpoint', trim((string) $discovered->authorization_endpoint));
        }
        if (isset($discovered->token_endpoint)) {
            $model->setData('access_token_endpoint', trim((string) $discovered->token_endpoint));
        }
        if (isset($discovered->userinfo_endpoint)) {
            $model->setData('user_info_endpoint', trim((string) $discovered->userinfo_endpoint));
        }
        if (isset($discovered->issuer)) {
            $model->setData('issuer', trim((string) $discovered->issuer));
        }
        if (isset($discovered->end_session_endpoint)) {
            $model->setData('endsession_endpoint', trim((string) $discovered->end_session_endpoint));
        }
        if (isset($discovered->revocation_endpoint)) {
            $model->setData('revocation_endpoint', trim((string) $discovered->revocation_endpoint));
        }
        if (isset($discovered->jwks_uri)) {
            $model->setData('jwks_endpoint', trim((string) $discovered->jwks_uri));
        }
        // Public-client hint: if the IdP advertises 'none' as a supported token-endpoint
        // auth method, this signals the server supports public clients. Pre-enable the
        // toggle so the admin does not have to set it manually.
        // Only applied on CREATE ($providerId === 0) — never on EDIT, so the admin's
        // explicit choice is always preserved when re-saving an existing provider.
        // Background: token_endpoint_auth_methods_supported is server-wide, not
        // per-client. Authelia and other IdPs list 'none' even for confidential clients,
        // so overriding on every save would incorrectly flip confidential clients to public.
        if ($providerId === 0
            && (int) $model->getData('public_client') === 0
            && isset($discovered->token_endpoint_auth_methods_supported)
            && is_array($discovered->token_endpoint_auth_methods_supported)
            && in_array('none', $discovered->token_endpoint_auth_methods_supported, true)
        ) {
            $model->setData('public_client', 1);
        }
    }

    /**
     * Fetch and parse an OIDC discovery document.
     *
     * Validates that the URL is HTTPS and does not point to a private/loopback address
     * (SSRF protection, same rules as OAuthsettings/Index.php).
     *
     * @param string $url Raw Discovery URL from the form.
     * @return \stdClass|null Parsed document on success; null on any validation or fetch error.
     */
    private function performDiscovery(string $url): ?\stdClass
    {
        // Must be a well-formed HTTPS URL.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $validated = filter_var($url, FILTER_VALIDATE_URL);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        if ($validated === false || parse_url($validated, PHP_URL_SCHEME) !== 'https') {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Discovery URL must be a valid HTTPS URL '
                    . '(e.g. https://provider.example.com/.well-known/openid-configuration).'
                )
            );
            return null;
        }

        // Block loopback and RFC-1918 private ranges (SSRF protection).
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        $host = (string) parse_url($validated, PHP_URL_HOST);

        if ($this->ssrfUrlValidator->isPrivateHost($host)) {
            $this->messageManager->addErrorMessage(
                (string) __('Discovery URL must not point to a private or internal network address.')
            );
            return null;
        }

        $body = $this->curl->sendUserInfoRequest($validated, []);
        $obj  = json_decode($body);

        if ($obj === null || !isset($obj->authorization_endpoint, $obj->token_endpoint)) {
            $this->messageManager->addErrorMessage(
                (string) __(
                    'Could not auto-discover endpoints. '
                    . 'The Discovery URL did not return a valid OIDC configuration document '
                    . '(missing authorization_endpoint or token_endpoint).'
                )
            );
            return null;
        }

        return $obj;
    }

    /**
     * Strip tags and trim a string field value.
     *
     * @param string $value
     */
    private function sanitizeString(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * Validate a 7-character CSS hex colour (#rrggbb).
     *
     * @param string $value
     * @return string Empty string when invalid.
     */
    private function validateHexColor(string $value): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : '';
    }
}
