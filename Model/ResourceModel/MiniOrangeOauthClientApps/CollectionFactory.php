<?php
namespace MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps;

use Magento\Framework\ObjectManagerInterface;

class CollectionFactory
{
    private \Magento\Framework\ObjectManagerInterface $objectManager;

    /**
     * Create a new collection instance.
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create collection instance
     */
    public function create(array $data = []) : Collection
    {
        return $this->objectManager->create(Collection::class, $data);
    }
}
