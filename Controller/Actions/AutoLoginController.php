<?php
/**
 * AutoLoginController - Controller für die automatische Anmeldung im Admin-Bereich
 * Diese Klasse stellt eine HTML-Seite bereit, die Cookies im Browser setzt und
 * dann automatisch zum Admin-Dashboard weiterleitet.
 */
namespace MiniOrange\OAuth\Controller\Actions;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

class AutoLoginController extends BaseAction implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $adminSession;

    /**
     * @var \Magento\User\Model\User
     */
    private $adminUser;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param OAuthUtility $oauthUtility
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        OAuthUtility $oauthUtility,
        PageFactory $resultPageFactory,
        JsonFactory $resultJsonFactory,
        \Magento\Backend\Model\Auth\Session $adminSession
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->adminSession = $adminSession;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $this->oauthUtility->customlog("AutoLoginController: Executing auto-login page");

        try {
            // Seite erstellen
            $resultPage = $this->resultPageFactory->create();
            
            // Prüfen, ob ein Block bereits existiert
            $block = $resultPage->getLayout()->getBlock('miniorange_oauth_auto_login');
            
            if (!$block) {
                $this->oauthUtility->customlog("AutoLoginController: Block not found. Creating block programmatically");
                
                // Block erstellen
                $block = $resultPage->getLayout()
                    ->createBlock(\Magento\Framework\View\Element\Template::class)
                    ->setTemplate('MiniOrange_OAuth::auto_login.phtml');
                
                // Block zum Layout hinzufügen
                $resultPage->getLayout()->setBlock('miniorange_oauth_auto_login', $block);
            }
            
            $this->oauthUtility->customlog("AutoLoginController: Auto-login page created successfully");
            return $resultPage;

        } catch (\Exception $e) {
            $this->oauthUtility->customlog("AutoLoginController: Error creating auto-login page: " . $e->getMessage());
            
            // Im Fehlerfall JSON-Antwort zurückgeben
            $resultJson = $this->resultJsonFactory->create();
            return $resultJson->setData([
                'success' => false,
                'message' => 'Ein Fehler ist aufgetreten: ' . $e->getMessage()
            ]);
        }
    }
    
    /**
     * Diese Methoden sind jetzt im Block nicht mehr notwendig, 
     * da wir die Parameter direkt über GET-Variablen abrufen.
     * Sie werden jedoch für die Abwärtskompatibilität beibehalten.
     */
}