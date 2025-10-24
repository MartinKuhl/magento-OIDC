<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Rolesettings;

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
use MiniOrange\OAuth\Helper\Curl;


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
     * database. It's called when you visis the moaoauth/attrsettings/Index
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
                $this->checkIfRequiredFieldsEmpty(['oauth_am_username'=>$params,'oauth_am_account_matcher'=>$params]);
                $this->processValuesAndSaveData($params);
                $this->oauthUtility->flushCache();
                $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                $this->oauthUtility->reinitConfig();
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage()) ;
        }
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu(OAuthConstants::MODULE_DIR.OAuthConstants::MODULE_BASE); $resultPage->addBreadcrumb(__('ATTR Settings'), __('ATTR Settings'));
        $resultPage->getConfig()->getTitle()->prepend(__(OAuthConstants::MODULE_TITLE));
        return $resultPage;
    }

    }



