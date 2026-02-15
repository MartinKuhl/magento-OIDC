<?php
/**
 * Psalm & PHPStan Stubs für Magento-generierte Factory-Klassen.
 *
 * Magento erzeugt diese Klassen zur Laufzeit via setup:di:compile.
 * Diese Stubs machen sie für statische Analyse-Tools bekannt.
 */

// phpcs:ignoreFile

namespace Magento\User\Model {
    class UserFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\User\Model\User
         */
        public function create(array $data = [])
        {
            return new \Magento\User\Model\User();
        }
    }
}

namespace Magento\User\Model\ResourceModel\User {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\User\Model\ResourceModel\User\Collection
         */
        public function create(array $data = [])
        {
            return new \Magento\User\Model\ResourceModel\User\Collection();
        }
    }
}

namespace Magento\Customer\Model {
    class CustomerFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Customer\Model\Customer
         */
        public function create(array $data = [])
        {
            return new \Magento\Customer\Model\Customer();
        }
    }
}

namespace Magento\Customer\Api\Data {
    class AddressInterfaceFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Customer\Api\Data\AddressInterface
         */
        public function create(array $data = [])
        {
            /** @var \Magento\Customer\Api\Data\AddressInterface */
            return new class implements \Magento\Customer\Api\Data\AddressInterface {};
        }
    }
}

namespace Magento\Directory\Model\ResourceModel\Country {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Directory\Model\ResourceModel\Country\Collection
         */
        public function create(array $data = [])
        {
            return new \Magento\Directory\Model\ResourceModel\Country\Collection();
        }
    }
}

namespace Magento\Framework\HTTP\Adapter {
    class CurlFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Framework\HTTP\Adapter\Curl
         */
        public function create(array $data = [])
        {
            return new \Magento\Framework\HTTP\Adapter\Curl();
        }
    }
}

namespace MiniOrange\OAuth\Model {
    class MiniorangeOauthClientAppsFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Framework\Model\AbstractModel
         */
        public function create(array $data = [])
        {
            return new \MiniOrange\OAuth\Model\MiniorangeOauthClientApps();
        }
    }
}
