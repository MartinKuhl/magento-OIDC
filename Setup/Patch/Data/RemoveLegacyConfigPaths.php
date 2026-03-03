<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Removes legacy global core_config_data entries that have been migrated
 * into per-provider columns in miniorange_oauth_client_apps.
 *
 * Must run AFTER MigrateGlobalConfigToProvider to ensure data is not lost.
 *
 * The following paths are intentionally NOT deleted (still global):
 * - miniorange\/oauth\/admin_email
 * - miniorange\/oauth\/debug_log
 * - miniorange\/oauth\/log_file_time
 * - miniorange\/oauth\/magento_count
 */
class RemoveLegacyConfigPaths implements DataPatchInterface
{
    /**
     * Providerspezifische Pfade, die bereits in miniorange_oauth_client_apps
     * migriert wurden und daher aus core_config_data entfernt werden können.
     */
    private const LEGACY_PATHS = [
        // Provider identity
        'miniorange/oauth/appName',

        // Global module meta — no longer needed
        'miniorange/oauth/time_stamp',
        'miniorange/oauth/send_email_config_data',

        // Auto-create flags
        'miniorange/oauth/autoCreateAdmin',
        'miniorange/oauth/autoCreateCustomer',

        // Role & group mapping
        'miniorange/oauth/defaultRole',
        'miniorange/oauth/adminRoleMapping',
        'miniorange/oauth/group',

        // SSO button visibility (show_customer_link / show_admin_link)
        'miniorange/oauth/showcustomerlink',
        'miniorange/oauth/showadminlink',

        // Login redirect / restriction
        'miniorange/oauth/enableLoginRedirect',
        'miniorange/oauth/disableNonOidcAdminLogin',
        'miniorange/oauth/disableNonOidcCustomerLogin',

        // Attribute mapping — identity
        'miniorange/oauth/amEmail',
        'miniorange/oauth/amUsername',
        'miniorange/oauth/amFirstName',
        'miniorange/oauth/amLastName',
        'miniorange/oauth/amDob',
        'miniorange/oauth/amGender',

        // Attribute mapping — address
        'miniorange/oauth/amPhone',
        'miniorange/oauth/amStreet',
        'miniorange/oauth/amZip',
        'miniorange/oauth/amCity',
        'miniorange/oauth/amState',
        'miniorange/oauth/amCountry',

        // Per-provider logout URL (→ endsession_endpoint / post_logout_url)
        'miniorange/oauth/oauthLogoutURL',

        // Per-provider test result cache (→ received_oidc_claims)
        'miniorange/oauth/receivedOidcClaims',
    ];

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function apply(): self
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('core_config_data');

        $connection->delete($table, [
            'path IN (?)' => self::LEGACY_PATHS,
        ]);

        return $this;
    }

    /**
     * {@inheritdoc}
     * Must run after migration patch to avoid data loss.
     */
    public static function getDependencies(): array
    {
        return [
            MigrateGlobalConfigToProvider::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }
}