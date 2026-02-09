<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Attrsettings;

use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\ResourceModel\Group\Collection;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use MiniOrange\OAuth\Helper\OAuthUtility;
use Psr\Log\LoggerInterface;



/**
 * This class handles the action for endpoint: mooauth/attrsettings/Index
 * Extends the \Magento\Backend\App\Action for Admin Actions which
 * inturn extends the \Magento\Framework\App\Action\Action class necessary
 * for each Controller class
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{

    private $adminRoleModel;
    private $userGroupModel;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        \Magento\Authorization\Model\ResourceModel\Role\Collection $adminRoleModel,
        Collection $userGroupModel
    ) {
        //You can use dependency injection to get any class this observer may need.
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
        $this->adminRoleModel = $adminRoleModel;
        $this->userGroupModel = $userGroupModel;
    }

    /**
     * The first function to be called when a Controller class is invoked.
     * Usually, has all our controller logic. Returns a view/page/template
     * to be shown to the users.
     *
     * This function gets and prepares all our SP config data from the
     * database. It's called when you visis the moasaml/attrsettings/Index
     * URL. It prepares all the values required on the SP setting
     * page in the backend and returns the block to be displayed.
     *
     * @return \Magento\Backend\Model\View\Result\Page
     */
    public function execute()
    {

        try {
            $params = $this->getRequest()->getParams(); //get params

            if ($this->isFormOptionBeingSaved($params)) { // check if form options are being saved
                $this->checkIfRequiredFieldsEmpty(['oauth_am_username' => $params, 'oauth_am_email' => $params]);
                $this->processValuesAndSaveData($params);
                $this->oauthUtility->flushCache();
                $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                $this->oauthUtility->reinitConfig();
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage());
        }
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__(OAuthConstants::MODULE_TITLE));
        return $resultPage;
    }


    /**
     * Process Values being submitted and save data in the database.
     * @param $param
     */
    private function processValuesAndSaveData($params)
    {
        //ToDo_MK extend for other attributes like first name, last name if needed
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_USERNAME, $params['oauth_am_username']);
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_EMAIL, $params['oauth_am_email']);

        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_FIRSTNAME, $params['oauth_am_first_name']);
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_LASTNAME, $params['oauth_am_last_name']);

        if (isset($params['dont_create_user_if_role_not_mapped'])) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::CREATEIFNOTMAP, $params['dont_create_user_if_role_not_mapped']);
        }

        if (isset($params['dont_allow_unlisted_user_role'])) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::UNLISTED_ROLE, $params['dont_allow_unlisted_user_role']);
        }

        // Save group attribute name for OIDC groups claim
        if (isset($params['oauth_am_group'])) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_GROUP, $params['oauth_am_group']);
        }

        // Save default admin role
        if (isset($params['oauth_am_default_role'])) {
            $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_DEFAULT_ROLE, $params['oauth_am_default_role']);
        }

        // Save admin role mappings as JSON (filter out empty mappings)
        if (isset($params['oauth_role_mapping'])) {
            $roleMappings = array_filter($params['oauth_role_mapping'], function ($mapping) {
                return !empty($mapping['group']) && !empty($mapping['role']);
            });
            $this->oauthUtility->setStoreConfig('adminRoleMapping', json_encode(array_values($roleMappings)), true);
            $this->oauthUtility->customlog("Saved admin role mappings: " . json_encode(array_values($roleMappings)));
        }

        // Save customer data mapping fields (directly from dropdown selection)
        $dobValue = $params['oauth_am_dob'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_DOB, $dobValue);
        $this->oauthUtility->customlog("Saved DOB mapping: " . $dobValue);

        $genderValue = $params['oauth_am_gender'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_GENDER, $genderValue);
        $this->oauthUtility->customlog("Saved gender mapping: " . $genderValue);

        $phoneValue = $params['oauth_am_phone'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_PHONE, $phoneValue);
        $this->oauthUtility->customlog("Saved phone mapping: " . $phoneValue);

        $streetValue = $params['oauth_am_street'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_STREET, $streetValue);
        $this->oauthUtility->customlog("Saved street mapping: " . $streetValue);

        $zipValue = $params['oauth_am_zip'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_ZIP, $zipValue);
        $this->oauthUtility->customlog("Saved zip mapping: " . $zipValue);

        $cityValue = $params['oauth_am_city'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_CITY, $cityValue);
        $this->oauthUtility->customlog("Saved city mapping: " . $cityValue);

        $countryValue = $params['oauth_am_country'] ?? '';
        $this->oauthUtility->setStoreConfig(OAuthConstants::MAP_COUNTRY, $countryValue);
        $this->oauthUtility->customlog("Saved country mapping: " . $countryValue);
    }

    /**
     * Is the user allowed to view the Attribute Mapping settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_ATTR);
    }
}
