<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Exception\RequiredFieldsException;

/**
 * The base action class that is inherited by each of the action
 * class. It consists of certain common functions that needs to
 * be inherited by each of the action class. Extends the
 * \Magento\Framework\App\Action\Action class which is usually
 * extended by Controller class.
 */
abstract class BaseAction extends \Magento\Framework\App\Action\Action
{

    /** @var \MiniOrange\OAuth\Helper\OAuthUtility */
    protected $oauthUtility;

    /** @var \Magento\Framework\App\Action\Context */
    protected $context;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \MiniOrange\OAuth\Helper\OAuthUtility $oauthUtility
    ) {
        //You can use dependency injection to get any class this observer may need.
        $this->oauthUtility = $oauthUtility;
        parent::__construct($context);
    }


    /**
     * Check if any of the required fields are empty. Throws RequiredFieldsException if so.
     *
     * Supports two calling conventions:
     *   1. checkIfRequiredFieldsEmpty(['key1' => $params, ...]) — legacy associative style
     *   2. checkIfRequiredFieldsEmpty(['key1', 'key2'], $params) — key list + params array
     *
     * @param array $array Required keys (or legacy associative array)
     * @param array|null $params Source data array (for the two-parameter form)
     * @throws RequiredFieldsException
     */
    protected function checkIfRequiredFieldsEmpty($array, $params = null)
    {
        if ($params !== null) {
            // Two-parameter form: $array is a list of required keys, $params is the data
            foreach ($array as $key) {
                if (!isset($params[$key]) || $this->oauthUtility->isBlank($params[$key])) {
                    throw new RequiredFieldsException();
                }
            }
            return;
        }

        // Legacy single-parameter form
        foreach ($array as $key => $value) {
            if ((is_array($value) && (!isset($value[$key]) || $this->oauthUtility->isBlank($value[$key])))
                || $this->oauthUtility->isBlank($value)
            ) {
                throw new RequiredFieldsException();
            }
        }
    }


    /**
     * This function is used to send AuthorizeRequest as a request Parameter.
     * LogoutRequest & AuthRequest is sent in the request parameter if the binding is
     * set as HTTP Redirect. Http Redirect is the default way Authn Request
     * is sent. Function also generates the signature and appends it in the
     * parameter as well along with the relayState parameter
    * @param string $oauthRequest Full authorize URL or query string
    * @param string $authorizeUrl Base authorize URL to prepend
    * @param string $relayState Optional relay state to include
    * @param array $params Additional parameters (unused)
     */
    protected function sendHTTPRedirectRequest($oauthRequest, $authorizeUrl, $relayState = '', $params = [])
    {
        $this->oauthUtility->customlog("BaseAction: sendHTTPRedirectRequest - Ensuring PHP session is properly saved before redirect");

        // Session handling relies on Magento specific session managers.
        // Manual session_write_close removed to prevent conflicts.

        $oauthRequest = $authorizeUrl . $oauthRequest;
        return $this->resultRedirectFactory->create()->setUrl($oauthRequest);
    }


    /** This function is abstract that needs to be implemented by each Action Class */
    abstract public function execute();
}
