<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Block\Adminhtml\Provider\Edit;

/**
 * Left-sidebar tab navigation for the OIDC Provider edit form.
 *
 * Registers three inline tabs:
 *   - Provider Settings  (identity / appearance)
 *   - OAuth Settings     (endpoints / credentials)
 *   - Attribute Mapping  (claim → Magento field mapping)
 */
class Tabs extends \Magento\Backend\Block\Widget\Tabs
{
    /**
     * Initialise the tabs widget.
     */
    protected function _construct(): void
    {
        parent::_construct();
        $this->setId('mooauth_provider_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle(__('Provider Information'));
    }

    /**
     * Register the three inline tabs and their child-block content.
     *
     * @return $this
     */
    protected function _beforeToHtml(): self
    {
        $this->addTab(
            'provider_settings',
            [
                'label'   => __('Provider Settings'),
                'title'   => __('Provider Settings'),
                'content' => $this->getChildHtml('mooauth.provider.tab.settings'),
                'active'  => true,
            ]
        );

        $this->addTab(
            'oauth_settings',
            [
                'label'   => __('OAuth Settings'),
                'title'   => __('OAuth Settings'),
                'content' => $this->getChildHtml('mooauth.provider.tab.oauth'),
            ]
        );

        $this->addTab(
            'attribute_mapping',
            [
                'label'   => __('Attribute Mapping'),
                'title'   => __('Attribute Mapping'),
                'content' => $this->getChildHtml('mooauth.provider.tab.attrs'),
            ]
        );

        return parent::_beforeToHtml();
    }
}
