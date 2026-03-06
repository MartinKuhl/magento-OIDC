<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

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
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /** @var DataPersistorInterface */
    private readonly DataPersistorInterface $dataPersistor;

    /**
     * Initialize provider save controller.
     *
     * @param Context                          $context
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     * @param DataPersistorInterface           $dataPersistor
     */
    public function __construct(
        Context $context,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource,
        DataPersistorInterface $dataPersistor
    ) {
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        $this->dataPersistor     = $dataPersistor;
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
                    'endsession_endpoint', 'issuer',
                    // Attribute mapping — basic claims
                    'email_attribute', 'username_attribute',
                    'firstname_attribute', 'lastname_attribute', 'group_attribute',
                    // Attribute mapping — customer data
                    'dob_attribute', 'gender_attribute',
                    'billing_address_attribute', 'billing_zip_attribute',
                    'billing_city_attribute', 'billing_state_attribute',
                    'billing_country_attribute', 'billing_phone_attribute',
                    'shipping_address_attribute', 'shipping_zip_attribute',
                    'shipping_city_attribute', 'shipping_state_attribute',
                    'shipping_country_attribute', 'shipping_phone_attribute',
                ] as $field) {
                if (isset($data[$field])) {
                    $model->setData($field, $this->sanitizeString($data[$field]));
                }
            }

            // Checkbox fields — absent in POST means unchecked (0)
            foreach ([
                'values_in_header',
                'values_in_body',
                // Login options
                'show_admin_link',
                'show_customer_link',
                'mo_oauth_auto_create_admin',
                'mo_oauth_auto_create_customer',
                'autoredirect',
                'mo_disable_non_oidc_admin_login',
                'mo_disable_non_oidc_customer_login',
                'oauth_am_sameasbilling',
                // Profile Sync on SSO Login
                'sync_customer_profile_on_sso',
                'sync_customer_address_on_sso',
                'sync_customer_group_on_sso',
                'sync_admin_profile_on_sso',
                'sync_admin_role_on_sso',
            ] as $checkbox) {
                $model->setData($checkbox, isset($data[$checkbox]) ? 1 : 0);
            }

            // Lockout-prevention: OIDC-only requires the SSO button to be shown
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
            $roleMappings = [];
            if (!empty($data['oauth_role_mapping']) && is_array($data['oauth_role_mapping'])) {
                foreach ($data['oauth_role_mapping'] as $row) {
                    $group = $this->sanitizeString($row['group'] ?? '');
                    $role  = $this->sanitizeString($row['role'] ?? '');
                    if ($group !== '' && $role !== '') {
                        $roleMappings[] = ['group' => $group, 'role' => $role];
                    }
                }
            }
            $model->setData('oauth_admin_role_mapping', json_encode($roleMappings));

            // Default customer group
            $model->setData('default_group', $this->sanitizeString($data['default_group'] ?? ''));

            // Customer group mappings: oauth_customer_group_mapping[N][group/customerGroup] → JSON
            $cgMappings = [];
            if (!empty($data['oauth_customer_group_mapping']) && is_array($data['oauth_customer_group_mapping'])) {
                foreach ($data['oauth_customer_group_mapping'] as $row) {
                    $group         = $this->sanitizeString($row['group'] ?? '');
                    $customerGroup = $this->sanitizeString($row['customerGroup'] ?? '');
                    if ($group !== '' && $customerGroup !== '') {
                        $cgMappings[] = ['group' => $group, 'customerGroup' => $customerGroup];
                    }
                }
            }
            $model->setData('oauth_customer_group_mapping', json_encode($cgMappings));

            // Encrypt client secret only when a new value is provided
            if (!empty($data['client_secret'])) {
                $model->setData('client_secret', $data['client_secret']);
            }

            $this->appResource->save($model);

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
