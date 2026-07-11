<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Provider\Edit;

/**
 * Left-sidebar tab navigation for the OIDC Provider edit form.
 *
 * Registers four inline tabs:
 *   - Provider Settings  (identity / appearance)
 *   - OAuth Settings     (endpoints / credentials)
 *   - Attribute Mapping  (claim → Magento field mapping)
 *   - Login Options      (login behavior / restrictions)
 */
class Tabs extends \Magento\Backend\Block\Widget\Tabs
{
    /**
     * Initialise the tabs widget.
     */
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('m2oidc_provider_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle(__('Provider Information'));
    }

    /**
     * Register the four inline tabs and their child-block content.
     */
    #[\Override]
    protected function _beforeToHtml(): self
    {
        $this->addTab(
            'provider_settings',
            [
                'label'   => __('Provider Settings'),
                'title'   => __('Provider Settings'),
                'content' => $this->getChildHtml('m2oidc.provider.tab.settings'),
                'active'  => true,
            ]
        );

        $this->addTab(
            'oauth_settings',
            [
                'label'   => __('OAuth Settings'),
                'title'   => __('OAuth Settings'),
                'content' => $this->getChildHtml('m2oidc.provider.tab.oauth'),
            ]
        );

        $this->addTab(
            'attribute_mapping',
            [
                'label'   => __('Attribute Mapping'),
                'title'   => __('Attribute Mapping'),
                'content' => $this->getChildHtml('m2oidc.provider.tab.attrs'),
            ]
        );

        $this->addTab(
            'login_options',
            [
                'label'   => __('Login Options'),
                'title'   => __('Login Options'),
                'content' => $this->getChildHtml('m2oidc.provider.tab.loginoptions'),
            ]
        );

        return parent::_beforeToHtml();
    }
}
