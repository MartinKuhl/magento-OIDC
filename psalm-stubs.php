<?php
/**
 * Psalm Stubs für Magento-generierte Factory-Klassen.
 *
 * Magento erzeugt diese Klassen zur Laufzeit via setup:di:compile.
 * Psalm kennt sie nicht – diese Stubs machen sie bekannt.
 */

// phpcs:ignoreFile

namespace Magento\User\Model {
    class UserFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\User\Model\User
         */
        public function create(array $data = []) {}
    }
}

namespace Magento\User\Model\ResourceModel\User {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\User\Model\ResourceModel\User\Collection
         */
        public function create(array $data = []) {}
    }
}

namespace Magento\Customer\Model {
    class CustomerFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Customer\Model\Customer
         */
        public function create(array $data = []) {}
    }
}

namespace Magento\Customer\Api\Data {
    class AddressInterfaceFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Customer\Api\Data\AddressInterface
         */
        public function create(array $data = []) {}
    }
}

namespace Magento\Directory\Model\ResourceModel\Country {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Directory\Model\ResourceModel\Country\Collection
         */
        public function create(array $data = []) {}
    }
}

namespace Magento\Framework\HTTP\Adapter {
    class CurlFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \Magento\Framework\HTTP\Adapter\Curl
         */
        public function create(array $data = []) {}
    }
}

namespace MiniOrange\OAuth\Model {
    class MiniorangeOauthClientAppsFactory
    {
        /**
         * @param array<string, mixed> $data
         * @return \MiniOrange\OAuth\Model\MiniorangeOauthClientApps
         */
        public function create(array $data = []) {}
    }
}
