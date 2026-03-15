<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use M2Oidc\OAuth\Model\M2oidcOauthClientAppsFactory;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps as AppResource;

/**
 * Admin controller — Edit / Add OIDC Provider form (MP-06).
 *
 * Route: GET /admin/m2oidc/provider/edit[/id/<providerId>]
 *
 * When `id` is absent or 0 the form opens in "Add new provider" mode.
 * When `id` is present the provider is loaded and stored in the Core Registry
 * so Block\Adminhtml\Provider\Edit and its tab children can pre-populate fields.
 *
 * @psalm-suppress DeprecatedClass
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'M2Oidc_OAuth::oauth_settings';

    /** @var PageFactory */
    private readonly PageFactory $pageFactory;

    /** @var Registry */
    private readonly Registry $registry;

    /** @var M2oidcOauthClientAppsFactory */
    private readonly M2oidcOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource */
    private readonly AppResource $appResource;

    /**
     * @param Context                          $context
     * @param PageFactory                      $pageFactory
     * @param Registry                         $registry
     * @param M2oidcOauthClientAppsFactory $clientAppsFactory
     * @param AppResource                      $appResource
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        Registry $registry,
        M2oidcOauthClientAppsFactory $clientAppsFactory,
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
        $model = null;

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
        $page->setActiveMenu('M2Oidc_OAuth::provider_management');

        $providerName = $providerId > 0
            ? ($model->getData('display_name') ?: $model->getData('app_name') ?: (string) $providerId)
            : '';
        $title = $providerId > 0
            ? __('Edit OIDC Provider (%1)', $providerName)
            : __('Add New OIDC Provider');

        $page->getConfig()->getTitle()->prepend((string) $title);
        $page->addBreadcrumb((string) __('M2Oidc OIDC'), (string) __('M2Oidc OIDC'));
        $page->addBreadcrumb(
            (string) __('Manage Providers'),
            (string) __('Manage Providers'),
            $this->getUrl('*/*/index')
        );
        $page->addBreadcrumb((string) $title, (string) $title);

        return $page;
    }
}
