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
    private Registry $registry;

    /** @var FormKey */
    private FormKey $formKeyHelper;

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
     * After parent::_construct() we clear $_blockGroup so that
     * Widget\Form\Container::_prepareLayout() skips auto-creating
     * a non-existent Form child block.
     */
    protected function _construct(): void
    {
        $this->_objectId   = 'id';
        $this->_blockGroup = 'MiniOrange_OAuth';
        $this->_controller = 'adminhtml_provider';

        parent::_construct();

        // Prevent Widget\Form\Container from auto-creating a Form child block
        $this->_blockGroup = '';

        // "Save and Continue Edit" button
        $this->buttonList->add(
            'save_and_continue',
            [
                'label'   => __('Save and Continue Edit'),
                'class'   => 'save',
                'onclick' => "document.getElementById('back').value='edit';"
                           . "jQuery('#edit_form').trigger('save');",
            ]
        );

        // "Test OIDC Flow" button — only for existing, saved providers.
        // Triggered directly by user click → no popup blocker issues.
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
                'class'      => 'action-secondary',
                // Direct user-click triggers window.open → popup blockers won't interfere
                'onclick'    => sprintf(
                    "window.open(%s, 'TEST_OIDC', 'scrollbars=1,width=800,height=600'); return false;",
                    json_encode($testUrl)
                ),
                'sort_order' => 42,
            ]
        );
    }

    /**
     * Build the frontend URL for the OIDC test flow.
     *
     * Uses the showTestResults URL + TEST_RELAYSTATE marker as relayState so that:
     * - SendAuthorizationRequest recognises the test mode via TEST_RELAYSTATE
     * - ReadAuthorizationResponse redirects to ShowTestResults after the callback
     *
     * @param \Magento\Framework\DataObject $provider
     * @return string
     */
    private function buildTestFlowUrl(\Magento\Framework\DataObject $provider): string
    {
        $store = $this->storeManager->getStore();

        // Combine showTestResults URL with TEST_RELAYSTATE marker ("testvalidate")
        // so ReadAuthorizationResponse reliably detects test mode via strpos()
        $relayState = $store->getUrl('mooauth/actions/showTestResults')
            . OAuthConstants::TEST_RELAYSTATE;

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
     *
     * @return Phrase|string
     */
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
     * Widget\Form\Container expects a Form child block, but we use Widget\Tabs
     * instead. We override getFormHtml() to render the form manually so that
     * the hidden "back" field is available for Save / Save & Continue.
     *
     * @return string
     */
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
             . '<input type="hidden" id="back" name="back" value="">'
             . '<input type="hidden" name="id" value="' . $providerId . '">'
             . $this->getChildHtml('mooauth_provider_edit_tabs')
             . '</form>';
    }
}
