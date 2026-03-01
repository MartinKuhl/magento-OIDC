<?php

namespace MiniOrange\OAuth\Controller\Adminhtml\Providersettings;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Controller\Actions\BaseAdminAction;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthMessages;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;
use Psr\Log\LoggerInterface;

/**
 * Provider Settings admin controller.
 *
 * Handles editing of provider identity fields:
 * display_name, login_type, is_active, sort_order, button_label, button_color.
 *
 * Only operates in provider-context mode (requires provider_id in the URL).
 *
 * @psalm-suppress ImplicitToStringCast Magento's __() returns Phrase with __toString()
 */
class Index extends BaseAdminAction implements HttpPostActionInterface, HttpGetActionInterface
{
    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * Initialize provider settings controller.
     *
     * @param Context                          $context
     * @param PageFactory                      $resultPageFactory
     * @param OAuthUtility                     $oauthUtility
     * @param ManagerInterface                 $messageManager
     * @param LoggerInterface                  $logger
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        OAuthUtility $oauthUtility,
        ManagerInterface $messageManager,
        LoggerInterface $logger,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource
    ) {
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        parent::__construct($context, $resultPageFactory, $oauthUtility, $messageManager, $logger);
    }

    /**
     * Display and process the Provider Settings form.
     *
     * @return \Magento\Framework\View\Result\Page
     */
    #[\Override]
    public function execute()
    {
        try {
            $params = $this->getRequest()->getParams();

            if ($this->isFormOptionBeingSaved($params)) {
                $providerId = (int) ($params['provider_id'] ?? 0);
                if ($providerId > 0) {
                    $model = $this->clientAppsFactory->create();
                    $this->appResource->load($model, $providerId);
                    if (!$model->getId()) {
                        $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                    } else {
                        $model->setData('display_name', trim(strip_tags((string) ($params['display_name'] ?? ''))));
                        $loginType = (string) ($params['login_type'] ?? 'customer');
                        if (!in_array($loginType, ['customer', 'admin', 'both'], true)) {
                            $loginType = 'customer';
                        }
                        $model->setData('login_type', $loginType);
                        $model->setData('is_active', isset($params['is_active']) ? 1 : 0);
                        $model->setData('sort_order', max(0, (int) ($params['sort_order'] ?? 0)));
                        $model->setData('button_label', trim(strip_tags((string) ($params['button_label'] ?? ''))));
                        $buttonColor = (string) ($params['button_color'] ?? '');
                        $model->setData(
                            'button_color',
                            preg_match('/^#[0-9a-fA-F]{6}$/', $buttonColor) ? $buttonColor : ''
                        );
                        $this->appResource->save($model);
                        $this->oauthUtility->flushCache();
                        $this->messageManager->addSuccessMessage(OAuthMessages::SETTINGS_SAVED);
                        $this->oauthUtility->reinitConfig();
                    }
                } else {
                    $this->messageManager->addErrorMessage(
                        (string) __('No provider selected. Please open this page via Manage Providers → Edit.')
                    );
                }
            }
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->oauthUtility->customlog($e->getMessage());
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->prepend(__('MiniOrange OAuth'));
        return $resultPage;
    }

    /**
     * ACL check for Provider Settings access.
     *
     * @return bool
     */
    #[\Override]
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(OAuthConstants::MODULE_DIR . 'provider_settings');
    }
}
