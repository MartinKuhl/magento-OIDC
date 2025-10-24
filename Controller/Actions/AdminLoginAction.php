<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\OAuthConstants;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * This class is called from the observer class to log the
 * admin user in. Read the appropriate values required from the
 * requset parameter passed along with the redirect to log the user in.
 * <b>NOTE</b> : Admin ID, Session Index and relaystate are passed
 *              in the request parameter.
 */
class AdminLoginAction extends BaseAction implements HttpPostActionInterface
{
    private $relayState;
    private $user;
    private $adminSession;
    private $cookieManager;
    private $adminConfig;
    private $cookieMetadataFactory;
    private $adminSessionManager;
    private $urlInterface;
    private $userFactory;
    protected $_resultPage;
    private $request;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility,
        \Magento\Backend\Model\Auth\Session $adminSession,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Backend\Model\Session\AdminConfig $adminConfig,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        \Magento\Security\Model\AdminSessionsManager $adminSessionManager,
        \Magento\Backend\Model\UrlInterface $urlInterface,
        \Magento\User\Model\UserFactory $userFactory,
        \Magento\Framework\App\RequestInterface $request
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->adminSession = $adminSession;
        $this->cookieManager = $cookieManager;
        $this->adminConfig =$adminConfig;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->adminSessionManager = $adminSessionManager;
        $this->urlInterface = $urlInterface;
        $this->userFactory = $userFactory;
        $this->request = $request;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Execute function to execute the classes function.
     */
    public function execute()
    {
            /**
             * Check if valid request by checking the SESSION_INDEX in the request
             * and the session index in the database. If they don't match then return
             * This is done to take care of the backdoor that this URL creates if no
             * session index is checked
             */
            $this->oauthUtility->customlog("AdminLoginAction: execute") ;
            $params = $this->request->getParams(); // get request params
            $user = $this->userFactory->create()->load($params['userid']);
            $this->adminSession->setUser($user);
            $this->adminSession->processLogin();

        if ($this->adminSession->isLoggedIn()) {
            $cookieValue = $this->adminSession->getSessionId();

            if ($cookieValue) {
                // generate admin cookie value - this is required to create a valid admin session
                $cookiePath = str_replace('autologin.php', 'index.php', $this->adminConfig->getCookiePath());
                $cookieMetadata = $this->cookieMetadataFactory->createPublicCookieMetadata()->setDuration(3600)
                                        ->setPath($cookiePath)->setDomain($this->adminConfig->getCookieDomain())
                                        ->setSecure($this->adminConfig->getCookieSecure())
                                        ->setHttpOnly($this->adminConfig->getCookieHttpOnly());
                
                // SameSite auf "None" setzen, um Cross-Origin-Cookie-Übertragung zu erlauben
                // Diese Methode ist ab Magento 2.4.0 verfügbar
                if (method_exists($cookieMetadata, 'setSameSite')) {
                    $cookieMetadata->setSameSite('None');
                    $this->oauthUtility->customlog("AdminLoginAction: Set SameSite=None for admin cookie");
                }
                $this->cookieManager->setPublicCookie($this->adminSession->getName(), $cookieValue, $cookieMetadata);
                
                // KORREKTUR: adminSessionManager ist vom Typ AdminSessionsManager, der keine processLogin-Methode hat
                // Wir nutzen stattdessen die richtige adminSession-Instanz
                if (method_exists($this->adminSession, 'processLogin')) {
                    $this->oauthUtility->customlog("AdminLoginAction: Calling processLogin on adminSession");
                    $this->adminSession->processLogin();
                } else {
                    $this->oauthUtility->customlog("AdminLoginAction: adminSession hat keine processLogin-Methode!");
                }
            }
        }


            // Direkte URL zum Dashboard verwenden, um ACL-Probleme zu vermeiden
            // Die Startup-Seite kann zu 'denied' umleiten, wenn ACL-Checks fehlschlagen
            $formKey = $this->adminSession->getFormKey();
            
            // Direkte Dashboard-URL verwenden statt der Startup-Seite
            $baseUrl = $this->urlInterface->getBaseUrl();
            $url = $baseUrl . 'admin/admin/dashboard';
            
            // FormKey direkt in die URL einfügen
            if ($formKey) {
                $url = $url . '/key/' . $formKey;
            }
            
            // Füge FormKey und Debug-Parameter hinzu
            if (strpos($url, 'key/') === false && $formKey) {
                // URL enthält noch keinen FormKey, fügen wir ihn hinzu
                $parsedUrl = parse_url($url);
                $path = $parsedUrl['path'];
                
                // Füge /key/FORMKEY zum Pfad hinzu
                $newPath = rtrim($path, '/') . '/key/' . $formKey;
                
                // URL wieder zusammenbauen
                $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
                $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
                $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
                $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
                
                $url = $scheme . $host . $port . $newPath . $query;
            }
            
            // Debug-Parameter hinzufügen
            $separator = (strpos($url, '?') !== false) ? '&' : '?';
            $url .= $separator . 'oauth_debug=1&moTime=' . time();
            
            // Noch eine Vorsichtsmaßnahme: Cookies über HTTP-Header setzen
            $sessionId = $this->adminSession->getSessionId();
            $this->getResponse()->setHeader('Set-Cookie', 'admin=' . $sessionId . '; Path=/admin; Secure; HttpOnly; SameSite=None');
            $this->getResponse()->setHeader('Set-Cookie', 'PHPSESSID=' . $sessionId . '; Path=/; Secure; HttpOnly; SameSite=None');
            
            // No-Cache-Header setzen
            $this->getResponse()->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $this->getResponse()->setHeader('Pragma', 'no-cache');
            $this->getResponse()->setHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
            
            $this->oauthUtility->customlog("AdminLoginAction: Redirecting to admin URL: " . $url);
            return $this->resultRedirectFactory->create()->setUrl($url);
    }
}
