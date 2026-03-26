<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Attrsettings;

use Magento\Backend\App\Action\Context;
use Magento\Customer\Model\ResourceModel\Group\Collection;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthMessages;
use M2Oidc\OAuth\Controller\Actions\BaseAdminAction;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;
use Psr\Log\LoggerInterface;

/**
 * This class handles the action for endpoint: m2oidc/attrsettings/Index
 * Extends the \Magento\Backend\App\Action for Admin Actions which
 * inturn extends the \Magento\Framework\App\Action\Action class necessary
 * for each Controller class
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 * @psalm-suppress DeprecatedInterface
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * Initialize attribute settings controller.
     *
     * @param Context                      $context
     * @param PageFactory                  $resultPageFactory
     * @param OAuthUtility                 $oauthUtility
     * @param ManagerInterface             $messageManager
     * @param LoggerInterface              $logger
     * @param M2oidcOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                  $appResource
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource
    ) {
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
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
     * @return \Magento\Framework\View\Result\Page
     */
    #[\Override]
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
        $resultPage->getConfig()->getTitle()->prepend((string)__('M2Oidc OAuth'));
        return $resultPage;
    }
    /**
     * Process Values being submitted and save data in the database.
     *
     * Saves directly to the specific provider's row in m2oidc_oauth_client_apps
     * identified by provider_id in $params.
     *
     * @param mixed[] $params
     */
    private function processValuesAndSaveData(array $params): void
    {
        $providerId = (int) ($params['provider_id'] ?? 0);

        if ($providerId > 0) {
            // --- Provider-context mode: UPDATE the specific provider row ---
            $model = $this->clientAppsFactory->create();
            $this->appResource->load($model, $providerId);
            if (!$model->getId()) {
                $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                return;
            }
            $model->setData('username_attribute', trim((string) ($params['oauth_am_username']   ?? '')));
            $model->setData('email_attribute', trim((string) ($params['oauth_am_email']      ?? '')));
            $model->setData('firstname_attribute', trim((string) ($params['oauth_am_first_name'] ?? '')));
            $model->setData('lastname_attribute', trim((string) ($params['oauth_am_last_name']  ?? '')));
            $model->setData('group_attribute', trim((string) ($params['oauth_am_group']      ?? '')));
            $model->setData('default_role', trim((string) ($params['oauth_am_default_role'] ?? '')));

            // Role mappings as JSON
            $roleMappings = array_filter(
                $params['oauth_role_mapping'] ?? [],
                static fn (array $m): bool => !empty($m['group']) && !empty($m['role'])
            );
            $model->setData('oauth_admin_role_mapping', json_encode($roleMappings));

            // Customer group mappings as JSON
            $customerGroupMappings = array_filter(
                $params['oauth_customer_group_mapping'] ?? [],
                static fn (array $m): bool => !empty($m['group']) && !empty($m['customerGroup'])
            );
            /** @psalm-suppress RedundantFunctionCall */
            $model->setData('oauth_customer_group_mapping', json_encode(array_values($customerGroupMappings)));
            $model->setData('default_group', trim((string) ($params['oauth_am_default_customer_group'] ?? '')));
            $model->setData('update_frontend_groups_on_sso', isset($params['update_frontend_groups_on_sso']) ? 1 : 0);

            $this->appResource->save($model);
            $this->oauthUtility->customlog(
                'Saved attribute mapping for provider ID ' . $providerId
            );
        }
    }

    /**
     * Is the user allowed to view the Attribute Mapping settings.
     * This is based on the ACL set by the admin in the backend.
     * Works in conjugation with acl.xml
     *
     * @return bool
     */
    #[\Override]
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . OAuthConstants::MODULE_ATTR);
    }
}
