<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\Provider\MappingRepository;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use M2Oidc\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;
use M2Oidc\OAuth\Model\ResourceModel\UserProvider as UserProviderResource;

/**
 * Admin controller — Save OIDC Provider (MP-06).
 *
 * Route: POST /admin/m2oidc/provider/save
 *
 * Handles both INSERT (id=0) and UPDATE (id>0). Validates the CSRF form
 * key automatically through Magento's CSRF validation layer.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'M2Oidc_OAuth::oauth_settings';

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

    /** @var UserProviderResource */
    private readonly UserProviderResource $userProviderResource;

    /** @var EncryptorInterface */
    private readonly EncryptorInterface $encryptor;

    /**
     * Initialize provider save controller.
     *
     * @param Context                       $context
     * @param M2oidcOauthClientAppsFactory  $clientAppsFactory
     * @param AppResource                   $appResource
     * @param DataPersistorInterface        $dataPersistor
     * @param MappingRepository             $mappingRepository
     * @param Curl                          $curl
     * @param UserProviderResource          $userProviderResource
     * @param EncryptorInterface            $encryptor
     */
    public function __construct(
        Context $context,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        DataPersistorInterface $dataPersistor,
        MappingRepository $mappingRepository,
        Curl $curl,
        UserProviderResource $userProviderResource,
        EncryptorInterface $encryptor
    ) {
        $this->clientAppsFactory    = $clientAppsFactory;
        $this->appResource          = $appResource;
        $this->dataPersistor        = $dataPersistor;
        $this->mappingRepository    = $mappingRepository;
        $this->curl                 = $curl;
        $this->userProviderResource = $userProviderResource;
        $this->encryptor            = $encryptor;
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

            // Sanitize and apply multi-provider fields
            $model->setData('app_name', $this->sanitizeString($data['app_name'] ?? ''));
            $model->setData('display_name', $this->sanitizeString($data['display_name'] ?? ''));
            $model->setData('is_active', (int) ($data['is_active'] ?? 1));
            $model->setData('login_type', $this->validateLoginType($data['login_type'] ?? 'customer'));
            $model->setData('sort_order', max(0, (int) ($data['sort_order'] ?? 0)));
            $model->setData('button_label', $this->sanitizeString($data['button_label'] ?? ''));
            $model->setData('button_color', $this->validateHexColor($data['button_color'] ?? ''));

            // Core endpoint and OAuth fields
            foreach ([
                'clientID', 'scope', 'grant_type',
                'authorize_endpoint', 'access_token_endpoint',
                'user_info_endpoint', 'jwks_endpoint', 'well_known_config_url',
                'endsession_endpoint', 'revocation_endpoint', 'issuer',
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
            ] as $field) {
                // FIX: use $field (not $checkbox) — variable name matches the foreach above.
                // (int) cast reads the actual "0"/"1" value sent by the hidden+checkbox pair.
                $model->setData($field, (int) ($data[$field] ?? 0));
            }

            // Lockout-prevention: OIDC-only requires the SSO button to be shown.
            // isset() is intentional here — we check POST presence, not the value.
            if (!isset($data['show_admin_link']) && isset($data['m2oidc_disable_non_oidc_admin_login'])) {
                $model->setData('m2oidc_disable_non_oidc_admin_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Admin OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the admin login page.'
                    )
                );
            }

            if (!isset($data['show_customer_link']) && isset($data['m2oidc_disable_non_oidc_customer_login'])) {
                $model->setData('m2oidc_disable_non_oidc_customer_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Customer OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the customer login page.'
                    )
                );
            }

            // Lockout-prevention: OIDC-only requires at least one OIDC user to exist for this provider.
            if ($model->getData('m2oidc_disable_non_oidc_admin_login') == 1
                && $providerId > 0
                && $this->userProviderResource->countByTypeAndProvider('admin', $providerId) === 0
            ) {
                $model->setData('m2oidc_disable_non_oidc_admin_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Admin OIDC-only login was automatically disabled because no admin users '
                        . 'have logged in via this provider yet.'
                    )
                );
            }

            if ($model->getData('m2oidc_disable_non_oidc_customer_login') == 1
                && $providerId > 0
                && $this->userProviderResource->countByTypeAndProvider('customer', $providerId) === 0
            ) {
                $model->setData('m2oidc_disable_non_oidc_customer_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Customer OIDC-only login was automatically disabled because no customers '
                        . 'have logged in via this provider yet.'
                    )
                );
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

            // Encrypt client secret only when a new value is provided
            if (!empty($data['client_secret'])) {
                $model->setData('client_secret', $this->encryptor->encrypt($data['client_secret']));
            }

            // PKCE method — only allow 'S256', 'plain', or '' (disabled)
            $pkceFlow = $this->sanitizeString($data['pkce_flow'] ?? '');
            if (!in_array($pkceFlow, ['S256', 'plain', ''], true)) {
                $pkceFlow = '';
            }
            $model->setData('pkce_flow', $pkceFlow);

            // Auto-discover endpoints when a Discovery URL is provided.
            // Overrides any manually-entered endpoint fields, consistent with the form hint.
            $discoveryUrl = trim((string) ($data['well_known_config_url'] ?? ''));
            $discoverySucceeded = false;
            if ($discoveryUrl !== '') {
                $discovered = $this->performDiscovery($discoveryUrl);
                if ($discovered instanceof \stdClass) {
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
                    $discoverySucceeded = true;
                }
                // On failure, performDiscovery() already added an error message.
                // Save still proceeds so the user does not lose other field values.
            }

            $this->appResource->save($model);

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
     * Fetch and parse an OIDC discovery document.
     *
     * Validates that the URL is HTTPS and does not point to a private/loopback address
     * (SEC-04 SSRF protection, same rules as OAuthsettings/Index.php).
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
        $isPrivate = in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || (bool) preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host);

        if ($isPrivate) {
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
     * Validate login_type — only 'customer', 'admin', 'both' are valid.
     *
     * @param string $value
     */
    private function validateLoginType(string $value): string
    {
        return in_array($value, ['customer', 'admin', 'both'], true) ? $value : 'customer';
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
