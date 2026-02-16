<?php
namespace MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps;

use Magento\Framework\ObjectManagerInterface;

class CollectionFactory
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Create a new collection instance.
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create collection instance
     *
     * @param  array $data
     * @return Collection
     */
    public function create(array $data = []) : Collection
    {
        return $this->objectManager->create(Collection::class, $data);
    }
}
