<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Removes legacy global core_config_data entries that have been migrated
 * into per-provider columns in m2oidc_oauth_client_apps.
 *
 * Must run AFTER MigrateGlobalConfigToProvider to ensure data is not lost.
 *
 * The following paths are intentionally NOT deleted (still global):
 * - m2oidc\/oauth\/admin_email
 * - m2oidc\/oauth\/debug_log
 * - m2oidc\/oauth\/log_file_time
 * - m2oidc\/oauth\/magento_count
 */
class RemoveLegacyConfigPaths implements DataPatchInterface
{
    /**
     * Providerspezifische Pfade, die bereits in m2oidc_oauth_client_apps
     * migriert wurden und daher aus core_config_data entfernt werden können.
     */
    private const array LEGACY_PATHS = [
        // Provider identity
        'm2oidc/oauth/appName',

        // Global module meta — no longer needed
        'm2oidc/oauth/time_stamp',
        'm2oidc/oauth/send_email_config_data',

        // Auto-create flags
        'm2oidc/oauth/autoCreateAdmin',
        'm2oidc/oauth/autoCreateCustomer',

        // Role & group mapping
        'm2oidc/oauth/defaultRole',
        'm2oidc/oauth/adminRoleMapping',
        'm2oidc/oauth/group',

        // SSO button visibility (show_customer_link / show_admin_link)
        'm2oidc/oauth/showcustomerlink',
        'm2oidc/oauth/showadminlink',

        // Login redirect / restriction
        'm2oidc/oauth/enableLoginRedirect',
        'm2oidc/oauth/disableNonOidcAdminLogin',
        'm2oidc/oauth/disableNonOidcCustomerLogin',

        // Attribute mapping — identity
        'm2oidc/oauth/amEmail',
        'm2oidc/oauth/amUsername',
        'm2oidc/oauth/amFirstName',
        'm2oidc/oauth/amLastName',
        'm2oidc/oauth/amDob',
        'm2oidc/oauth/amGender',

        // Attribute mapping — address
        'm2oidc/oauth/amPhone',
        'm2oidc/oauth/amStreet',
        'm2oidc/oauth/amZip',
        'm2oidc/oauth/amCity',
        'm2oidc/oauth/amState',
        'm2oidc/oauth/amCountry',

        // Per-provider logout URL (→ endsession_endpoint / post_logout_url)
        'm2oidc/oauth/oauthLogoutURL',

        // Per-provider test result cache (→ received_oidc_claims)
        'm2oidc/oauth/receivedOidcClaims',
    ];

    /** @var ResourceConnection */
    private ResourceConnection $resourceConnection;

    /**
     * Constructor.
     *
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Delete legacy config paths from core_config_data.
     */
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
     * @inheritdoc
     *
     * Must run after migration patch to avoid data loss.
     */
    public static function getDependencies(): array
    {
        return [
            MigrateGlobalConfigToProvider::class,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
