<?php
namespace MiniOrange\OAuth\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Debug Block für Authelia Response Daten
 */
class Debug extends Template
{
    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    protected \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility;

    /** @var \Magento\Framework\App\Filesystem\DirectoryList */
    protected \Magento\Framework\App\Filesystem\DirectoryList $directoryList;

    /** @var \Magento\Framework\Filesystem\Driver\File|null */
    protected ?\Magento\Framework\Filesystem\Driver\File $fileDriver;

    /** @var \Magento\Framework\HTTP\Client\Curl|null */
    protected ?\Magento\Framework\HTTP\Client\Curl $curlClient;

    /**
     * Initialize Debug block.
     *
     * @param Context                                         $context
     * @param OAuthUtility                                    $oauthUtility
     * @param DirectoryList                                   $directoryList
     * @param \Magento\Framework\Filesystem\Driver\File|null  $fileDriver
     * @param \Magento\Framework\HTTP\Client\Curl|null        $curlClient
     * @param array                                           $data
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        DirectoryList $directoryList,
        \Magento\Framework\Filesystem\Driver\File $fileDriver = null,
        \Magento\Framework\HTTP\Client\Curl $curlClient = null,
        array $data = []
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->directoryList = $directoryList;
        // optional DI for environments where driver/client are not configured
        if (!$fileDriver instanceof \Magento\Framework\Filesystem\Driver\File
            || !$curlClient instanceof \Magento\Framework\HTTP\Client\Curl
        ) {
            $om = \Magento\Framework\App\ObjectManager::getInstance();
            if (!$fileDriver instanceof \Magento\Framework\Filesystem\Driver\File) {
                $fileDriver = $om->get(\Magento\Framework\Filesystem\Driver\File::class);
            }
            if (!$curlClient instanceof \Magento\Framework\HTTP\Client\Curl) {
                $curlClient = $om->get(\Magento\Framework\HTTP\Client\Curl::class);
            }
        }

        $this->fileDriver = $fileDriver;
        $this->curlClient = $curlClient;

        parent::__construct($context, $data);
    }

    /**
     * Get OIDC Configuration
     */
    public function getOidcConfiguration(): array
    {
        $clientId = $this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_ID);
        $clientSecret = $this->maskSecret($this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_SECRET));
        $authorizeEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_URL);
        $tokenEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::ACCESSTOKEN_URL);
        $userInfoEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::GETUSERINFO_URL);
        $callbackUrl = $this->getUrl('', ['_direct' => 'mooauth/actions/readauthorizationresponse']);
        $scope = $this->oauthUtility->getStoreConfig(OAuthConstants::SCOPE);
        $emailMapping = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL)
            ?: OAuthConstants::DEFAULT_MAP_EMAIL;
        $usernameMapping = $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME)
            ?: OAuthConstants::DEFAULT_MAP_USERN;

        return [
            'Client ID' => $clientId,
            'Client Secret' => $clientSecret,
            'Authorization Endpoint' => $authorizeEndpoint,
            'Token Endpoint' => $tokenEndpoint,
            'User Info Endpoint' => $userInfoEndpoint,
            'Callback URL' => $callbackUrl,
            'Scope' => $scope,
            'Email Attribute Mapping' => $emailMapping,
            'Username Attribute Mapping' => $usernameMapping,
            'Logging Enabled' => $this->oauthUtility->isLogEnable() ? 'Yes' : 'No',
            'Log File' => '/var/log/mo_oauth.log'
        ];
    }

    /**
     * Get Last OAuth Response from Session
     *
     * @return array|null
     */
    public function getLastOAuthResponse()
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerSession = $objectManager->get(\Magento\Customer\Model\Session::class);

            $response = $customerSession->getData('mo_oauth_debug_response');
            if ($response) {
                return json_decode((string) $response, true);
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('Debug::getLastOAuthResponse exception: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Get Recent Log Entries
     */
    public function getRecentLogEntries(): array
    {
        $logFile = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/log/mo_oauth.log';
        $entries = [];

        try {
            if ($this->fileDriver->isExists($logFile)) {
                $contents = $this->fileDriver->fileGetContents($logFile);
                $lines = preg_split('/\r\n|\n|\r/', $contents);
                $recentLines = array_slice($lines, -50);
                foreach ($recentLines as $line) {
                    $entries[] = trim((string) $line);
                }
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog('Debug::getRecentLogEntries exception: ' . $e->getMessage());
        }

        return array_reverse($entries);
    }

    /**
     * Test Authelia Connection
     */
    public function testAutheliaConnection(): array
    {
        $results = [];

        $authEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_URL);
        if ($authEndpoint) {
            $results['Authorization Endpoint'] = $this->testUrl($authEndpoint);
        }

        $tokenEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::ACCESSTOKEN_URL);
        if ($tokenEndpoint) {
            $results['Token Endpoint'] = $this->testUrl($tokenEndpoint);
        }

        $userInfoEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::GETUSERINFO_URL);
        if ($userInfoEndpoint) {
            $results['UserInfo Endpoint'] = $this->testUrl($userInfoEndpoint);
        }

        return $results;
    }

    /**
     * Test URL connectivity
     *
     * @param  string $url
     */
    protected function testUrl($url): array
    {
        $curl = $this->curlClient;
        $responseTime = null;
        $error = null;
        $httpCode = null;

        try {
            $curl->setOption('CURLOPT_NOBODY', true);
            $curl->setOption('CURLOPT_TIMEOUT', 5);
            $curl->setOption('CURLOPT_SSL_VERIFYPEER', false);

            $startTime = microtime(true);
            $curl->get($url);
            $endTime = microtime(true);

            $httpCode = $curl->getStatus();
            $responseTime = round(((float) $endTime - (float) $startTime) * 1000.0, 2);
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }

        return [
            'status' => $httpCode ?: 'Error',
            'reachable' => !empty($httpCode),
            'response_time' => $responseTime !== null ? (string)$responseTime . ' ms' : null,
            'error' => $error
        ];
    }

    /**
     * Mask sensitive data
     *
     * @param  string $secret
     */
    protected function maskSecret($secret): string
    {
        if (empty($secret)) {
            return 'Not configured';
        }

        $length = strlen($secret);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($secret, 0, 4) . str_repeat('*', $length - 4);
    }

    /**
     * Format JSON for display
     *
     * @param  mixed $data
     */
    public function formatJson($data): string
    {
        if (is_string($data)) {
            $decoded = json_decode($data);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }

        $result = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($result === false) {
            $fallback = json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
            if ($fallback === false) {
                return '{"error":"JSON encoding failed"}';
            }
            return $fallback;
        }

        return $result;
    }

    /**
     * Get System Information
     */
    public function getSystemInfo(): array
    {
        return [
            'PHP Version' => PHP_VERSION,
            'Magento Version' => $this->oauthUtility->getProductVersion(),
            'Plugin Version' => '1.0.0',
            'Curl Installed' => extension_loaded('curl') ? 'Yes' : 'No',
            'OpenSSL Version' => OPENSSL_VERSION_TEXT,
            'Server Time' => date('Y-m-d H:i:s T'),
            'Timezone' => date_default_timezone_get()
        ];
    }
}
