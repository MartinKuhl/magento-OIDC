<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps;

use Magento\Framework\ObjectManagerInterface;

class CollectionFactory
{
    /** @var \Magento\Framework\ObjectManagerInterface */
    private readonly \Magento\Framework\ObjectManagerInterface $objectManager;

    /**
     * Create a new collection instance.
     *
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create collection instance
     *
     * @param  mixed[] $data
     */
    public function create(array $data = []) : Collection
    {
        return $this->objectManager->create(Collection::class, $data);
    }
}
