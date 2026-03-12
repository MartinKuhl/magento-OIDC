<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\Provider\MappingRepository;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;
use MiniOrange\OAuth\Model\ResourceModel\OauthRoleMapping as RoleMappingResource;

/**
 * Admin controller — Save OIDC Provider (MP-06).
 *
 * Route: POST /admin/mooauth/provider/save
 *
 * Handles both INSERT (id=0) and UPDATE (id>0). Validates the CSRF form
 * key automatically through Magento's CSRF validation layer.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var DataPersistorInterface */
    private readonly DataPersistorInterface $dataPersistor;

    /** @var MappingRepository */
    private readonly MappingRepository $mappingRepository;

    /**
     * Initialize provider save controller.
     *
     * @param Context                          $context
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     * @param DataPersistorInterface           $dataPersistor
     * @param MappingRepository                $mappingRepository
     */
    public function __construct(
        Context $context,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        DataPersistorInterface $dataPersistor,
        MappingRepository $mappingRepository
    ) {
        $this->clientAppsFactory  = $clientAppsFactory;
        $this->appResource        = $appResource;
        $this->dataPersistor      = $dataPersistor;
        $this->mappingRepository  = $mappingRepository;
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
                'mo_oauth_auto_create_admin',
                'mo_oauth_auto_create_customer',
                'autoredirect_admin',    // Auto-redirect for admin login page
                'autoredirect_customer', // Auto-redirect for customer login page
                'mo_disable_non_oidc_admin_login',
                'mo_disable_non_oidc_customer_login',
                // Profile Sync on SSO Login
                'sync_customer_profile_on_sso',
                'sync_customer_address_on_sso',
                'sync_customer_group_on_sso',
                'sync_admin_profile_on_sso',
                'sync_admin_role_on_sso',
            ] as $field) {
                // FIX: use $field (not $checkbox) — variable name matches the foreach above.
                // (int) cast reads the actual "0"/"1" value sent by the hidden+checkbox pair.
                $model->setData($field, (int) ($data[$field] ?? 0));
            }

            // Lockout-prevention: OIDC-only requires the SSO button to be shown.
            // isset() is intentional here — we check POST presence, not the value.
            if (!isset($data['show_admin_link']) && isset($data['mo_disable_non_oidc_admin_login'])) {
                $model->setData('mo_disable_non_oidc_admin_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Admin OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the admin login page.'
                    )
                );
            }

            if (!isset($data['show_customer_link']) && isset($data['mo_disable_non_oidc_customer_login'])) {
                $model->setData('mo_disable_non_oidc_customer_login', 0);
                $this->messageManager->addWarningMessage(
                    (string) __(
                        'Customer OIDC-only login was automatically disabled because the OIDC '
                        . 'login button is not shown on the customer login page.'
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
                $model->setData('client_secret', $data['client_secret']);
            }

            // PKCE method — only allow 'S256', 'plain', or '' (disabled)
            $pkceFlow = $this->sanitizeString($data['pkce_flow'] ?? '');
            if (!in_array($pkceFlow, ['S256', 'plain', ''], true)) {
                $pkceFlow = '';
            }
            $model->setData('pkce_flow', $pkceFlow);

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

            $this->messageManager->addSuccessMessage((string) __('Provider saved successfully.'));
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
