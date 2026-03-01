<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Provider;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Admin controller — Edit / Add OIDC Provider form (MP-06).
 *
 * Route: GET /admin/mooauth/provider/edit[/id/<providerId>]
 *
 * When `id` is absent or 0 the form opens in "Add new provider" mode.
 */
class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var PageFactory */
    private readonly PageFactory $pageFactory;

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * Initialize provider edit controller.
     *
     * @param Context      $context
     * @param PageFactory  $pageFactory
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        OAuthUtility $oauthUtility
    ) {
        $this->pageFactory  = $pageFactory;
        $this->oauthUtility = $oauthUtility;
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
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider === null) {
                $this->messageManager->addErrorMessage((string) __('Provider not found.'));
                return $this->resultRedirectFactory->create()
                    ->setPath('*/*/index');
            }
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
