<?php

namespace MiniOrange\OAuth\Controller\Actions;

use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\Exception\AccountAlreadyExistsException;
use MiniOrange\OAuth\Helper\Exception\NotRegisteredException;

/**
 * Handles processing of customer login page form or the
 * registration page form if it was found that a user
 * exists in the system.
 *
 * The main function of this action class is to authenticate
 * the user credentials as provided by calling an API and
 * fetching all of the relevant information of the customer.
 * Store the key, token and email in the database.
 */
class LoginExistingUserAction extends BaseAdminAction
{
    private $REQUEST;
    
    /**
     * Execute function to execute the classes function.
     *
     * @throws \Exception
     */
    public function execute()
    {

          

            $this->checkIfRequiredFieldsEmpty(['email'=>$this->REQUEST,
                                                        'password'=>$this->REQUEST,
                                                                                            'submit'=>$this->REQUEST]);
            $email = $this->REQUEST['email'];
            $password = $this->REQUEST['password'];
            $submit = $this->REQUEST['submit'];
            $this->getCurrentCustomer($email, $password);
            $this->oauthUtility->flushCache("LoginExistingUserAction ");
    }


    /**
     * Function is used to make a cURL call which will fetch
     * the user's data based on the username password provided
     * by the user.
     *
     * @param $email
     * @param $password
     * @throws AccountAlreadyExistsException
     */
    private function getCurrentCustomer($email, $password)
    {
                $content = Curl::get_customer_key($email, $password);
                $customerKey = json_decode($content, true);
           $this->oauthUtility->customlog("LogExistingUserAction: getCurrentCustomer") ;
        if (json_last_error() == JSON_ERROR_NONE) {
            // set the user values in the database
                        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_EMAIL, $email);
                        $this->oauthUtility->setStoreConfig(OAuthConstants::CUSTOMER_KEY, $customerKey['id']);
                        $this->oauthUtility->setStoreConfig(OAuthConstants::API_KEY, $customerKey['apiKey']);
                        $this->oauthUtility->setStoreConfig(OAuthConstants::TOKEN, $customerKey['token']);
                        $this->oauthUtility->setStoreConfig(OAuthConstants::TXT_ID, '');
                        $this->oauthUtility->setStoreConfig(OAuthConstants::REG_STATUS, OAuthConstants::STATUS_COMPLETE_LOGIN);

                        
                        $timeStamp = $this->oauthUtility->getStoreConfig(OAuthConstants::TIME_STAMP);
                        if($timeStamp == null){
                            $timeStamp = time();
                            $this->oauthUtility->setStoreConfig(OAuthConstants::TIME_STAMP,$timeStamp);
                            $this->oauthUtility->flushCache();
                        }
                        $domain = $this->oauthUtility->getBaseUrl();
                        $currentAdminUser =  $this->oauthUtility->getCurrentAdminUser();  
                        $adminEmail = $currentAdminUser['email'];
                        $miniorangeAccountEmail = $email;
                        $pluginFirstPageVisit = '';
                        $environmentName = $this->oauthUtility->getEdition();
                        $environmentVersion = $this->oauthUtility->getProductVersion();
                        $freeInstalledDate = $this->oauthUtility->getCurrentDate();
                        $identityProvider = $this->oauthUtility->getStoreConfig(OAuthConstants::APP_NAME);
                        $testSuccessful = '';
                        $testFailed = '';
                        $autoCreateLimit = '';















                        $this->messageManager->addSuccessMessage(OAuthMessages::REG_SUCCESS);
        } else {
            // wrong credentials provided or there was some error in fetching the user details
//          $this->oauthUtility->setStoreConfig('miniorange/oauth/registration/status',OAuthConstants::STATUS_VERIFY_LOGIN);
                      $this->oauthUtility->setStoreConfig(OAuthConstants::REG_STATUS, OAuthConstants::STATUS_VERIFY_LOGIN);
            throw new AccountAlreadyExistsException;
        }
    }

    /** Setter for the request Parameter
     * @param $request
     * @return LoginExistingUserAction
     */
    public function setRequestParam($request)
    {
                $this->REQUEST = $request;
                return $this;
    }
}
