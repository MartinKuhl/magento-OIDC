<?php
namespace MiniOrange\OAuth\Helper;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;

/**
 * Session handler plugin for miniOrange OAuth SSO
 * This class configures the PHP session for cross-origin usage
 *
 * Compatible with Magento 2.4.7 - 2.4.8-p3
 */
class SessionHelper
{
    /**
     * Known admin-related cookie names to update with SameSite=None.
     * Used instead of iterating $_COOKIE directly.
     */
    private const ADMIN_COOKIE_NAMES = ['PHPSESSID', 'admin'];

    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var OAuthUtility
     */
    private $oauthUtility;

    /**
     * @var BackendUrlInterface
     */
    private $backendUrl;

    /**
     * Initialize session helper.
     *
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory  $cookieMetadataFactory
     * @param OAuthUtility           $oauthUtility
     * @param BackendUrlInterface    $backendUrl
     */
    public function __construct(
        CookieManagerInterface $cookieManager,
        CookieMetadataFactory $cookieMetadataFactory,
        OAuthUtility $oauthUtility,
        BackendUrlInterface $backendUrl
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->oauthUtility = $oauthUtility;
        $this->backendUrl = $backendUrl;
    }

    /**
     * Configures the PHP session for SSO
     */
    public function configureSSOSession(): void
    {
        // Manual session management removed to comply with Magento standards.
        // We rely on CookieManager to set SameSite attributes via updateSessionCookies.
        $this->updateSessionCookies();
    }

    /**
     * Re-set existing session cookies with SameSite=None.
     *
     * Handles both frontend and admin cookies.
     * Uses Magento's CookieManager for 2.4.7+ compatibility.
     */
    public function updateSessionCookies(): void
    {
        try {
            // Obtain configured session name without using session_* functions
            $sessionName = ini_get('session.name') ?: 'PHPSESSID';

            // Handle the frontend session cookie (/ path)
            $cookieValue = $this->cookieManager->getCookie($sessionName);
            if ($cookieValue !== null) {
                /**
 * @var PublicCookieMetadata $metadata
*/
                $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setPath('/')
                    ->setSecure(true)
                    ->setHttpOnly(true)
                    ->setSameSite('None');

                $this->cookieManager->setPublicCookie($sessionName, $cookieValue, $metadata);
            }

            // Also update admin session cookies with SameSite=None
            $adminFrontName = $this->backendUrl->getAreaFrontName();
            $adminCookieCandidates = self::ADMIN_COOKIE_NAMES;
            // Include the admin-prefixed session cookie name as a candidate
            $adminCookieCandidates[] = $adminFrontName;

            foreach ($adminCookieCandidates as $candidateName) {
                if ($candidateName === $sessionName) {
                    // Already handled above
                    continue;
                }

                $candidateValue = $this->cookieManager->getCookie($candidateName);
                if ($candidateValue === null) {
                    continue;
                }

                $path = (strpos($candidateName, $adminFrontName) !== false) ? '/' . $adminFrontName : '/';

                /**
 * @var PublicCookieMetadata $metadata
*/
                $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                    ->setPath($path)
                    ->setSecure(true)
                    ->setHttpOnly(true)
                    ->setSameSite('None');

                $this->cookieManager->setPublicCookie($candidateName, $candidateValue, $metadata);
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("SessionHelper: Exception in updateSessionCookies: " . $e->getMessage());
        }
    }

    /**
     * Set SameSite=None on the PHP session cookie only.
     *
     * Only updates the session cookie - does not modify other cookies.
     *
     * @return void
     */
    public function forceSameSiteNone()
    {
        try {
            // Avoid direct session_* usage — prefer CookieManager for cookie access
            $sessionName = ini_get('session.name') ?: 'PHPSESSID';
            $sessionId = $this->cookieManager->getCookie($sessionName);

            if (empty($sessionId)) {
                return;
            }

            /**
 * @var PublicCookieMetadata $metadata
*/
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                ->setSecure(true)
                ->setHttpOnly(true)
                ->setSameSite('None');

            $this->cookieManager->setPublicCookie($sessionName, $sessionId, $metadata);
        } catch (\Exception $e) {
            $this->oauthUtility->customlog("SessionHelper: Error in forceSameSiteNone: " . $e->getMessage());
        }
    }
}
