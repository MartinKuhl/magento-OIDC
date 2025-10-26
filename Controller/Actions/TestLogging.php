<?php
namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Test controller to debug logging configuration
 */
class TestLogging implements ActionInterface, HttpGetActionInterface
{
    protected $oauthUtility;
    protected $resultFactory;
    protected $request;

    public function __construct(
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        ResultFactory $resultFactory,
        RequestInterface $request
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->resultFactory = $resultFactory;
        $this->request = $request;
    }

    public function execute()
    {
        $output = "<h1>Logging Configuration Test</h1>";
        
        // Test 1: Prüfe isLogEnable()
        $isLogEnabled = $this->oauthUtility->isLogEnable();
        $output .= "<p><strong>isLogEnable():</strong> " . ($isLogEnabled ? 'TRUE (Logging aktiviert)' : 'FALSE (Logging deaktiviert)') . "</p>";
        
        // Test 2: Prüfe die Konfigurationswerte direkt
        $debugLogConfig = $this->oauthUtility->getStoreConfig('miniorange/oauth/debug_log');
        $output .= "<p><strong>Config 'miniorange/oauth/debug_log':</strong> " . var_export($debugLogConfig, true) . "</p>";
        
        $oldDebugLogConfig = $this->oauthUtility->getStoreConfig(OAuthConstants::ENABLE_DEBUG_LOG);
        $output .= "<p><strong>Config ENABLE_DEBUG_LOG constant:</strong> " . var_export($oldDebugLogConfig, true) . "</p>";
        
        // Test 3: Prüfe Logger-Objekt
        $output .= "<p><strong>Logger-Objekt existiert:</strong> " . (isset($this->oauthUtility) ? 'JA' : 'NEIN') . "</p>";
        
        // Test 4: Versuche direktes Logging
        $output .= "<h2>Logging-Tests:</h2>";
        
        // Test mit customlog()
        $this->oauthUtility->customlog("TEST 1: Direct customlog() call from TestLogging controller");
        $output .= "<p>✓ customlog() aufgerufen mit: 'TEST 1: Direct customlog() call from TestLogging controller'</p>";
        
        // Test mit log_debug()
        $this->oauthUtility->log_debug("TEST 2: Direct log_debug() call from TestLogging controller");
        $output .= "<p>✓ log_debug() aufgerufen mit: 'TEST 2: Direct log_debug() call from TestLogging controller'</p>";
        
        // Test 5: Prüfe Dateiberechtigungen
        $logFile = BP . '/var/log/mo_oauth.log';
        $output .= "<h2>Datei-Informationen:</h2>";
        $output .= "<p><strong>Log-Datei-Pfad:</strong> $logFile</p>";
        
        if (file_exists($logFile)) {
            $output .= "<p><strong>Datei existiert:</strong> JA</p>";
            $output .= "<p><strong>Datei beschreibbar:</strong> " . (is_writable($logFile) ? 'JA' : 'NEIN') . "</p>";
            $output .= "<p><strong>Dateigröße:</strong> " . filesize($logFile) . " Bytes</p>";
            $output .= "<p><strong>Letzte Änderung:</strong> " . date('Y-m-d H:i:s', filemtime($logFile)) . "</p>";
            
            // Zeige die letzten 10 Zeilen der Log-Datei
            $output .= "<h2>Letzte Log-Einträge:</h2>";
            $output .= "<pre style='background:#f5f5f5;padding:10px;border:1px solid #ccc;'>";
            $logContent = file_get_contents($logFile);
            $lines = explode("\n", $logContent);
            $lastLines = array_slice($lines, -10);
            $output .= htmlspecialchars(implode("\n", $lastLines));
            $output .= "</pre>";
        } else {
            $output .= "<p><strong>Datei existiert:</strong> NEIN</p>";
            $varLogDir = BP . '/var/log';
            $output .= "<p><strong>var/log Verzeichnis beschreibbar:</strong> " . (is_writable($varLogDir) ? 'JA' : 'NEIN') . "</p>";
            
            // Versuche die Datei zu erstellen
            $output .= "<h2>Versuche Log-Datei zu erstellen:</h2>";
            try {
                $testWrite = @file_put_contents($logFile, "Test-Eintrag: " . date('Y-m-d H:i:s') . "\n");
                if ($testWrite !== false) {
                    $output .= "<p style='color:green;'>✓ Log-Datei wurde erfolgreich erstellt!</p>";
                } else {
                    $output .= "<p style='color:red;'>✗ Konnte Log-Datei nicht erstellen. Überprüfen Sie Dateiberechtigungen.</p>";
                }
            } catch (\Exception $e) {
                $output .= "<p style='color:red;'>✗ Fehler beim Erstellen: " . $e->getMessage() . "</p>";
            }
        }
        
        $output .= "<hr><p><em>Überprüfen Sie nun var/log/mo_oauth.log auf die TEST-Einträge.</em></p>";
        
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $resultPage->setContents($output);
        return $resultPage;
    }
}
