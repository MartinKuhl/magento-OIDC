<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Block\Adminhtml\Provider\Edit\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;

/**
 * Provider Settings tab — identity and appearance fields.
 */
class ProviderSettings extends Template implements TabInterface
{
    /** @var string */
    protected $_template = 'M2Oidc_OAuth::provider/tab/providersettings.phtml';

    /** @var Registry */
    private readonly Registry $registry;

    /**
     * @param Context  $context
     * @param Registry $registry
     * @param mixed[]  $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * Return the current provider data, or an empty array for new providers.
     *
     * @return array<string, mixed>
     */
    public function getProviderData(): array
    {
        $provider = $this->registry->registry('current_oidc_provider');
        return $provider ? $provider->getData() : [];
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getTabLabel(): Phrase|string
    {
        return __('Provider Settings');
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function getTabTitle(): Phrase|string
    {
        return __('Provider Settings');
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function canShowTab(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }
}
