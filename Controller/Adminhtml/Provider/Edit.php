<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;

/**
 * Admin controller — Edit / Add OIDC Provider form (MP-06).
 *
 * Route: GET /admin/mooauth/provider/edit[/id/<providerId>]
 *
 * When `id` is absent or 0 the form opens in "Add new provider" mode.
 * When `id` is present the provider is loaded and stored in the Core Registry
 * so Block\Adminhtml\Provider\Edit and its tab children can pre-populate fields.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var PageFactory */
    private readonly PageFactory $pageFactory;

    /** @var Registry */
    private readonly Registry $registry;

    /** @var MiniorangeOauthClientAppsFactory */
    private readonly MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * @param Context                          $context
     * @param PageFactory                      $pageFactory
     * @param Registry                         $registry
     * @param MiniorangeOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        Registry $registry,
        MiniorangeOauthClientAppsFactory $clientAppsFactory,
        AppResource $appResource
    ) {
        $this->pageFactory       = $pageFactory;
        $this->registry          = $registry;
        $this->clientAppsFactory = $clientAppsFactory;
        $this->appResource       = $appResource;
        parent::__construct($context);
    }

    /**
     * Render the provider edit form or redirect to list on invalid ID.
     */
    #[\Override]
    public function execute(): Page|Redirect
    {
        $providerId = (int) $this->getRequest()->getParam('id', 0);

        if ($providerId > 0) {
            $model = $this->clientAppsFactory->create();
            $this->appResource->load($model, $providerId);

            if (!$model->getId()) {
                $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }

            // Share the loaded model with Block\Adminhtml\Provider\Edit and its tabs.
            $this->registry->register('current_oidc_provider', $model);
        }

        $page = $this->pageFactory->create();
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page->setActiveMenu('MiniOrange_OAuth::provider_management');

        $title = $providerId > 0
            ? __('Edit OIDC Provider (ID: %1)', $providerId)
            : __('Add New OIDC Provider');

        $page->getConfig()->getTitle()->prepend((string) $title);
        $page->addBreadcrumb((string) __('MiniOrange OIDC'), (string) __('MiniOrange OIDC'));
        $page->addBreadcrumb(
            (string) __('Manage Providers'),
            (string) __('Manage Providers'),
            $this->getUrl('*/*/index')
        );
        $page->addBreadcrumb((string) $title, (string) $title);

        return $page;
    }
}
