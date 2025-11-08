<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\OAuthsettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\OAuth\SAML2Utilities;
use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;




/**
 * This class handles the action for endpoint: mooauth/oauthsettings/Index
 * Extends the \Magento\Backend\App\Action for Admin Actions which
 * inturn extends the \Magento\Framework\App\Action\Action class necessary
 * for each Controller class
 */

class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
     /**
      * The first function to be called when a Controller class is invoked.
      * Usually, has all our controller logic. Returns a view/page/template
      * to be shown to the users.
      *
      * This function gets and prepares all our OAuth config data from the
      * database. It's called when you visis the mooauth/oauthsettings/Index
      * URL. It prepares all the values required on the SP setting
      * page in the backend and returns the block to be displayed.
      *
      * @return \Magento\Framework\View\Result\Page
      */


    public function execute()
    {
        try {
            $params = $this->getRequest()->getParams(); //get params

            // check if form options are being saved
            if ($this->isFormOptionBeingSaved($params) && isset($params['endpoint_radio_button'])) {
                //Store radio button value in $radiostate parameter.
                $radiostate=$params['endpoint_radio_button'];
                //check whether URL radio button is checked or manual radio button is checked.

                if ($radiostate=='byurl') {


                     $url = $params['endpoint_url'];

                     if ($url!=NULL) {

                         //get URL content
                                $url = filter_var($url, FILTER_SANITIZE_URL);

                                $file = file_get_contents($url);

                                $obj = json_decode($file);
                                $this->checkIfRequiredFieldsEmpty(['mo_oauth_app_name' => $params, 'mo_oauth_client_id' => $params,
                                    'mo_oauth_client_secret' => $params, 'mo_oauth_scope' => $params]);
                            //check if url has any information or not.
                            if($obj!=NULL)
                            { /**
                             * Fetch endpoints from data obtained from URL
                                */

                                $mo_oauth_authorize_url = $obj->authorization_endpoint; //authorization_endpoint

                                $mo_oauth_accesstoken_url = $obj->token_endpoint;  //token_endpoint

                                $mo_oauth_getuserinfo_url = isset($obj->userinfo_endpoint) ? $obj->userinfo_endpoint : ''; //userinfo_endpoint

                                /**
                                 * Trim and Store Endpoint parameter in miniorange_oauth_client_apps table.
                                 * */

                                $params['mo_oauth_authorize_url']=trim($mo_oauth_authorize_url);
                                $params['mo_oauth_accesstoken_url']=trim($mo_oauth_accesstoken_url);
                                $params['mo_oauth_getuserinfo_url']=trim($mo_oauth_getuserinfo_url);

                                $this->checkIfRequiredFieldsEmpty(['mo_oauth_app_name' => $params, 'mo_oauth_client_id' => $params,
                                    'mo_oauth_client_secret' => $params, 'mo_oauth_scope' => $params,
                                    'mo_oauth_authorize_url' => $params, 'mo_oauth_accesstoken_url' => $params,
                                    ]);
                                $this->processValuesAndSaveData($params);
                                $this->oauthUtility->flushCache();
                                $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                                $this->oauthUtility->reinitConfig();

                            }
                            else{
                                $this->messageManager->addErrorMessage('Please Enter Valid URL');
                            $this->oauthUtility->customlog('URL do not have any information.Please enter valid url') ;

                            }


                        } else {

                            $this->messageManager->addErrorMessage('Please Enter URL');
                             $this->oauthUtility->customlog('URL is empty.Please enter valid  url') ;
                        }



                } else {


                        if($radiostate=='bymanual') {

                                $this->checkIfRequiredFieldsEmpty(['mo_oauth_app_name' => $params, 'mo_oauth_client_id' => $params,
                                    'mo_oauth_client_secret' => $params, 'mo_oauth_scope' => $params,
                                    'mo_oauth_authorize_url' => $params, 'mo_oauth_accesstoken_url' => $params,
                                    ]);
                                $this->processValuesAndSaveData($params);
                                $this->oauthUtility->flushCache();
                                $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                                $this->oauthUtility->reinitConfig();
                        }else{
                            $this->messageManager->addErrorMessage('Please Select Required OAuth Endpoints option');
                      $this->oauthUtility->customlog('Error in Controller->Adminhtml->OAuthsettings->index file...Please Select Required OAuth Endpoints option') ;
                        }


                }
                // check if required values have been submitted

                $this->oauthUtility->reinitConfig();

            }
        }catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
             $this->oauthUtility->customlog($e->getMessage()) ;
        }

       

        // generate page
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__(OAuthConstants::MODULE_TITLE));
        return $resultPage;
    }

     /**
      * Process Values being submitted and save data in the database.
      */
    private function processValuesAndSaveData($params)
    {
        $mo_oauth_app_name        = trim($params['mo_oauth_app_name']);
        $mo_oauth_client_id       = trim($params['mo_oauth_client_id']);
              $mo_oauth_client_secret   = trim($params['mo_oauth_client_secret']);
              $mo_oauth_scope           = trim($params['mo_oauth_scope']);
              $mo_oauth_authorize_url   = trim($params['mo_oauth_authorize_url']);
              $mo_oauth_accesstoken_url = trim($params['mo_oauth_accesstoken_url']);
              $mo_oauth_getuserinfo_url = trim($params['mo_oauth_getuserinfo_url']);
              $mo_oauth_well_known_config_url =isset($params['endpoint_url']) ? trim($params['endpoint_url']): '';
              $mo_oauth_grant_type      = OAuthConstants::GRANT_TYPE;
              $send_header = isset($params['send_header']) ? 1 : 0;
              $send_body = isset($params['send_body']) ? 1 : 0;        
            
            
              $collection = $this->oauthUtility->getOAuthClientApps();
              $clientDetails=null;
              foreach($collection as $item){
                  if($item->getData()["app_name"]===$mo_oauth_app_name){
                      $clientDetails=$item->getData();
                  }
                }
            
            //removing all previous records so at a time only 1 app_name is shown(free)
            $this->oauthUtility->deleteAllRecords();
                
              //for storing it in custom table
            $this->oauthUtility->setOAuthClientApps(
                $mo_oauth_app_name,
                $mo_oauth_client_id,
                $mo_oauth_client_secret,
                $mo_oauth_scope,
                $mo_oauth_authorize_url,
                $mo_oauth_accesstoken_url,
                $mo_oauth_getuserinfo_url,
                $mo_oauth_well_known_config_url,
                $mo_oauth_grant_type,
                $send_header,
                $send_body,
            );

            $this->oauthUtility->setStoreConfig(OAuthConstants::APP_NAME, $mo_oauth_app_name);
            $this->oauthUtility->setStoreConfig(OAuthConstants::SHOW_CUSTOMER_LINK, 1);
    
            $currentAdminUser =  $this->oauthUtility->getCurrentAdminUser()->getData();  
                $userEmail = $currentAdminUser['email'];
    
             $this->oauthUtility->setStoreConfig(OAuthConstants::ADMINEMAIL,$userEmail);


    }

     /**
      * Is the user allowed to view the Service Provider settings.
      * This is based on the ACL set by the admin in the backend.
      * Works in conjugation with acl.xml
      *
      * @return bool
      */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR.OAuthConstants::MODULE_OAUTHSETTINGS);
    }
}
