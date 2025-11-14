<?php
namespace MiniOrange\OAuth\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Debug Block für Authelia Response Daten
 */
class Debug extends Template
{
    /**
     * @var OAuthUtility
     */
    protected $oauthUtility;

    /**
     * @param Context $context
     * @param OAuthUtility $oauthUtility
     * @param array $data
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        array $data = []
    ) {
        $this->oauthUtility = $oauthUtility;
        parent::__construct($context, $data);
    }

    /**
     * Get OIDC Configuration
     * 
     * @return array
     */
    public function getOidcConfiguration()
    {
        // ToDo: Fetch actual configuration values
        return [
            'Client ID' => $this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_ID),
            'Client Secret' => $this->maskSecret($this->oauthUtility->getStoreConfig(OAuthConstants::CLIENT_SECRET)),
            'Authorization Endpoint' => $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_ENDPOINT),
            'Token Endpoint' => $this->oauthUtility->getStoreConfig(OAuthConstants::ACCESS_TOKEN_EP),
            'User Info Endpoint' => $this->oauthUtility->getStoreConfig(OAuthConstants::GET_USER_INFO_EP),
            'Callback URL' => $this->getUrl('', ['_direct' => 'mooauth/actions/readauthorizationresponse']),
            'Scope' => $this->oauthUtility->getStoreConfig(OAuthConstants::SCOPE),
            'Email Attribute Mapping' => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_EMAIL) ?: OAuthConstants::DEFAULT_MAP_EMAIL,
            'Username Attribute Mapping' => $this->oauthUtility->getStoreConfig(OAuthConstants::MAP_USERNAME) ?: OAuthConstants::DEFAULT_MAP_USERN,
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
        // Try to get from customer session or admin session
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerSession = $objectManager->get(\Magento\Customer\Model\Session::class);
            
            $response = $customerSession->getData('mo_oauth_debug_response');
            if ($response) {
                return json_decode($response, true);
            }
        } catch (\Exception $e) {
            // Session data not available
        }

        return null;
    }

    /**
     * Get Recent Log Entries
     * 
     * @return array
     */
    public function getRecentLogEntries()
    {
        $logFile = BP . '/var/log/mo_oauth.log';
        $entries = [];

        if (file_exists($logFile)) {
            $lines = file($logFile);
            $recentLines = array_slice($lines, -50); // Last 50 lines

            foreach ($recentLines as $line) {
                $entries[] = trim($line);
            }
        }

        return array_reverse($entries);
    }

    /**
     * Test Authelia Connection
     * 
     * @return array
     */
    public function testAutheliaConnection()
    {
        $results = [];
        
        // Test Authorization Endpoint
        $authEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::AUTHORIZE_ENDPOINT);
        if ($authEndpoint) {
            $results['Authorization Endpoint'] = $this->testUrl($authEndpoint);
        }

        // Test Token Endpoint
        $tokenEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::ACCESS_TOKEN_EP);
        if ($tokenEndpoint) {
            $results['Token Endpoint'] = $this->testUrl($tokenEndpoint);
        }

        // Test UserInfo Endpoint
        $userInfoEndpoint = $this->oauthUtility->getStoreConfig(OAuthConstants::GET_USER_INFO_EP);
        if ($userInfoEndpoint) {
            $results['UserInfo Endpoint'] = $this->testUrl($userInfoEndpoint);
        }

        return $results;
    }

    /**
     * Test URL connectivity
     * 
     * @param string $url
     * @return array
     */
    protected function testUrl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $startTime = microtime(true);
        curl_exec($ch);
        $endTime = microtime(true);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $responseTime = round(($endTime - $startTime) * 1000, 2);

        return [
            'status' => $httpCode ?: 'Error',
            'reachable' => !empty($httpCode),
            'response_time' => $responseTime . ' ms',
            'error' => $error ?: null
        ];
    }

    /**
     * Mask sensitive data
     * 
     * @param string $secret
     * @return string
     */
    protected function maskSecret($secret)
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
     * @param mixed $data
     * @return string
     */
    public function formatJson($data)
    {
        if (is_array($data)) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return json_encode(json_decode($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get System Information
     * 
     * @return array
     */
    public function getSystemInfo()
    {
        return [
            'PHP Version' => PHP_VERSION,
            'Magento Version' => $this->oauthUtility->getProductVersion(),
            'Plugin Version' => '1.0.0',
            'Curl Installed' => function_exists('curl_version') ? 'Yes (v' . curl_version()['version'] . ')' : 'No',
            'OpenSSL Version' => OPENSSL_VERSION_TEXT,
            'Server Time' => date('Y-m-d H:i:s T'),
            'Timezone' => date_default_timezone_get()
        ];
    }
}
