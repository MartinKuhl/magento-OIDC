<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider;

use Magento\Backend\Block\Widget\Context;
use Magento\Backend\Block\Widget\Form\Container;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use MiniOrange\OAuth\Helper\OAuthConstants;

/**
 * Admin edit-form container for the OIDC Provider.
 *
 * Renders the Magento-standard page header (breadcrumbs, action buttons) and
 * wraps the Widget\Tabs sidebar form in a <form> element that posts to
 * mooauth/provider/save.
 */
class Edit extends Container
{
    /** @var Registry */
    private readonly Registry $registry;

    /** @var FormKey */
    private readonly FormKey $formKeyHelper;

    /** @var StoreManagerInterface */
    private readonly StoreManagerInterface $storeManager;

    /**
     * @param Context               $context
     * @param Registry              $registry
     * @param FormKey               $formKey
     * @param StoreManagerInterface $storeManager
     * @param array                 $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormKey $formKey,
        StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->registry      = $registry;
        $this->formKeyHelper = $formKey;
        $this->storeManager  = $storeManager;
        parent::__construct($context, $data);
    }

    /**
     * Set block identifiers and configure action buttons.
     *
     * Save / Save & Continue use hidden submit buttons inside the <form>
     * triggered via onclick from the buttonList toolbar buttons.
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_objectId   = 'id';
        $this->_blockGroup = 'MiniOrange_OAuth';
        $this->_controller = 'adminhtml_provider';

        parent::_construct();

        // Prevent Widget\Form\Container from auto-creating a Form child block
        $this->_blockGroup = '';

        // Replace default "Save" with our own that triggers the hidden submit
        $this->buttonList->remove('save');
        $this->buttonList->add(
            'save',
            [
                'label'   => __('Save'),
                'class'   => 'save primary',
                'onclick' => "document.getElementById('btn_save').click(); return false;",
            ]
        );

        // "Save and Continue Edit" — triggers hidden submit with back=edit
        $this->buttonList->add(
            'save_and_continue',
            [
                'label'   => __('Save and Continue Edit'),
                'class'   => 'save',
                'onclick' => "document.getElementById('btn_save_continue').click(); return false;",
            ]
        );

        // "Test OIDC Flow" button — only for existing, saved providers.
        $this->addTestOidcFlowButton();
    }

    /**
     * Add the "Test OIDC Flow" button for existing providers.
     *
     * Opens the OIDC test flow directly in a popup window via user click.
     * No save is performed — the provider must already be saved before testing.
     */
    private function addTestOidcFlowButton(): void
    {
        $provider = $this->registry->registry('current_oidc_provider');

        if (!$provider || !$provider->getId() || !$provider->getData('app_name')) {
            return;
        }

        $testUrl = $this->buildTestFlowUrl($provider);

        $this->buttonList->add(
            'test_oidc_flow',
            [
                'label'      => __('Test OIDC Flow'),
                'class'      => 'save',
                'onclick'    => sprintf(
                    "window.open(%s, 'TEST_OIDC', 'scrollbars=1,width=800,height=600'); return false;",
                    json_encode($testUrl)
                ),
                'sort_order' => 25,
            ]
        );
    }

    /**
     * Build the frontend URL for the OIDC test flow.
     *
     * @param \Magento\Framework\DataObject $provider
     */
    private function buildTestFlowUrl(\Magento\Framework\DataObject $provider): string
    {
        $store = $this->storeManager->getStore();

        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        $relayState = $store->getUrl('mooauth/actions/showTestResults')
            . OAuthConstants::TEST_RELAYSTATE;

        /** @psalm-suppress UndefinedInterfaceMethod */
        // @phpstan-ignore-next-line
        return $store->getUrl(
            'mooauth/actions/sendAuthorizationRequest',
            [
                'option'      => OAuthConstants::TEST_CONFIG_OPT,
                'app_name'    => $provider->getData('app_name'),
                'provider_id' => (int) $provider->getId(),
                'relayState'  => $relayState,
            ]
        );
    }

    /**
     * Return the page header text.
     */
    #[\Override]
    public function getHeaderText(): Phrase|string
    {
        $provider = $this->registry->registry('current_oidc_provider');

        if ($provider && $provider->getId()) {
            $name = (string) ($provider->getData('display_name') ?: $provider->getData('app_name'));
            return __("Edit Provider '%1'", $this->escapeHtml($name));
        }

        return __('New OIDC Provider');
    }

    /**
     * Render the <form> element wrapping the Widget\Tabs child block.
     *
     * Hidden submit buttons inside the form carry the correct name/value
     * for the "back" parameter. The visible toolbar buttons (buttonList)
     * trigger these via onclick → consistent styling + reliable POST data.
     */
    #[\Override]
    public function getFormHtml(): string
    {
        $providerId = (int) $this->getRequest()->getParam('id', 0);
        $url        = $this->escapeUrl($this->getUrl('mooauth/provider/save'));
        $formKey    = $this->escapeHtmlAttr($this->formKeyHelper->getFormKey());

        return '<form id="edit_form"'
            . ' action="' . $url . '"'
            . ' method="post"'
            . ' enctype="multipart/form-data">'
            . '<input type="hidden" name="form_key" value="' . $formKey . '">'
            . '<input type="hidden" name="id" value="' . $providerId . '">'
            // Hidden submit buttons — triggered by toolbar onclick
            . '<input type="submit" id="btn_save" name="back" value="" style="display:none">'
            . '<input type="submit" id="btn_save_continue" name="back" value="edit" style="display:none">'
            . $this->getChildHtml('mooauth_provider_edit_tabs')
            . '</form>';
    }
}
