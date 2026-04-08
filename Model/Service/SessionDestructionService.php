<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Service;

use Magento\Framework\App\ResourceConnection;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Shared service for destroying PHP sessions and clearing Magento Online status.
 *
 * Extracted from BackChannelLogout so that FrontChannelLogout can reuse the
 * same session-destruction logic (C-02 pattern) without code duplication.
 *
 * Thread safety: PHP's file-based session handler holds an exclusive flock()
 * for the duration of session_start(), so concurrent reads from the target
 * session's owner request will block until session_destroy() is called.
 * The try-finally block ensures the original session ID is always restored.
 */
class SessionDestructionService
{
    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var ResourceConnection */
    private readonly ResourceConnection $resourceConnection;

    /**
     * @param OAuthUtility       $oauthUtility
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        OAuthUtility $oauthUtility,
        ResourceConnection $resourceConnection
    ) {
        $this->oauthUtility       = $oauthUtility;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Destroy a PHP session by its session ID.
     *
     * C-02: destroying a session belonging to a different browser request.
     * PHP has no direct API for this; switching session IDs via session_id()
     * is the de-facto standard approach used by Symfony, Laravel, and others.
     *
     * @param string $phpSessionId Target PHP session ID
     */
    public function destroySession(string $phpSessionId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.,:-]+$/', $phpSessionId)) {
            // Reject malformed IDs to prevent path traversal in file-based handlers
            $this->oauthUtility->customlog(
                'SessionDestructionService: Rejected malformed session ID during destroy.'
            );
            return;
        }
        // phpcs:disable Magento2.Functions.DiscouragedFunction.Discouraged, Magento2.Security.Superglobal
        $currentId = session_id();
        session_commit();
        session_id($phpSessionId);
        try {
            session_start(['read_and_close' => false]);
            $_SESSION = [];
            session_destroy();
        } finally {
            // Always restore original session ID, even if an exception is thrown
            session_id($currentId !== false ? $currentId : '');
            if ($currentId !== false && $currentId !== '') {
                session_start(['read_and_close' => false]);
            }
        }
        // phpcs:enable Magento2.Functions.DiscouragedFunction.Discouraged, Magento2.Security.Superglobal
    }

    /**
     * Clear the "Online" status in core Magento tables after a session is destroyed.
     *
     * PHP session destruction removes the session data file/record but does not
     * update the separate DB tables used by SessionDataProvider:
     *   - admin:    admin_user_session.status (1 = active, 0 = logged out)
     *   - customer: customer_log.last_logout_at
     *
     * @param mixed[] $entry Session entry from OidcSessionRegistry::resolve()
     */
    public function clearOnlineStatus(array $entry): void
    {
        $userType = (string) ($entry['user_type'] ?? '');
        $userId   = (int)    ($entry['user_id']   ?? 0);

        try {
            $conn = $this->resourceConnection->getConnection();
            if ($userType === 'admin' && $userId > 0) {
                $conn->update(
                    $this->resourceConnection->getTableName('admin_user_session'),
                    ['status' => 0],
                    ['user_id = ?' => $userId, 'status = ?' => 1]
                );
            } elseif ($userType === 'customer' && $userId > 0) {
                $conn->update(
                    $this->resourceConnection->getTableName('customer_log'),
                    ['last_logout_at' => (new \DateTime())->format('Y-m-d H:i:s')],
                    ['customer_id = ?' => $userId]
                );
            }
        } catch (\Exception $e) {
            // Non-fatal: PHP session already destroyed; UI corrects itself on next load.
            $this->oauthUtility->customlog(
                'SessionDestructionService: clearOnlineStatus failed (non-fatal): ' . $e->getMessage()
            );
        }
    }
}
