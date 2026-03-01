<?php

declare(strict_types=1);

/**
 * Minimal Magento class / interface stubs for standalone PHPUnit tests.
 *
 * This file is included by Test/bootstrap.php ONLY when the full Magento
 * framework is NOT available (e.g. in CI where only phpunit/phpunit is
 * installed).  Each stub provides just enough structure so that:
 *
 *  1. PHP can parse and define MiniOrange\OAuth\* classes whose parent
 *     classes or implemented interfaces live in the Magento namespace.
 *  2. PHPUnit can create mock objects for those Magento types directly.
 *
 * Keep these stubs as minimal as possible; add new stubs only when a CI
 * test failure proves they are needed.
 */

// ─── Interfaces ────────────────────────────────────────────────────────────────

namespace Magento\Framework\Message {
    interface ManagerInterface
    {
        public function addSuccessMessage($message);
        public function addErrorMessage($message);
        public function addWarningMessage($message);
    }
}

namespace Magento\Framework\Event {
    interface ManagerInterface
    {
        public function dispatch($eventName, array $data = []);
    }
}

namespace Magento\Backend\Model\Auth\Credential {
    /**
     * Methods mirror the real StorageInterface so that #[\Override] attributes
     * on OidcCredentialAdapter resolve correctly (PHP 8.3+).
     */
    interface StorageInterface
    {
        public function authenticate($username, $password);
        public function login($username, $password);
        public function reload();
        public function hasAvailableResources();
        public function setHasAvailableResources($hasResources);
    }
}

// ─── Abstract base classes ────────────────────────────────────────────────────

namespace Magento\Framework\App\Helper {
    abstract class AbstractHelper
    {
        /** @var mixed */
        protected $scopeConfig;

        public function __construct($context = null)
        {
        }
    }
}

namespace Magento\Backend\App {
    /**
     * Stub for the admin-controller base class.
     * BaseAdminAction extends this and calls parent::__construct($context).
     */
    class Action
    {
        /** @var mixed */
        protected $_authorization;

        public function __construct($context = null)
        {
        }
    }
}

namespace Magento\Framework\Model {
    abstract class AbstractModel
    {
        /** @return mixed */
        public function getId()
        {
            return null;
        }

        /** @return mixed */
        public function getData($key = null, $default = null)
        {
            return $default;
        }

        /** @return static */
        public function setData($key, $value = null)
        {
            return $this;
        }

        /** @return static */
        public function addData(array $data)
        {
            return $this;
        }

        /** @return static */
        public function setId($id)
        {
            return $this;
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db {
    abstract class AbstractDb
    {
        public function load($object, $value, $field = null): void
        {
        }

        public function save($object): void
        {
        }
    }
}

namespace Magento\Framework\Model\ResourceModel\Db\Collection {
    abstract class AbstractCollection
    {
        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        public function getSize(): int
        {
            return 0;
        }
    }
}

// ─── Concrete Magento classes (directly mocked in tests) ─────────────────────

namespace Magento\Framework\App\Request {
    class Http
    {
        /** @return array<string,mixed> */
        public function getParams(): array
        {
            return [];
        }
    }
}

namespace Magento\Framework\View\Result {
    class PageFactory
    {
        public function create(array $data = []): Page
        {
            return new Page();
        }
    }

    class Page
    {
        public function getConfig(): \Magento\Framework\View\Page\Config
        {
            return new \Magento\Framework\View\Page\Config();
        }
    }
}

namespace Magento\Framework\View\Page {
    class Config
    {
        public function getTitle(): Title
        {
            return new Title();
        }
    }

    class Title
    {
        public function prepend($text): void
        {
        }
    }
}

namespace Magento\Backend\App\Action {
    class Context
    {
        /** @return mixed */
        public function getRequest()
        {
            return null;
        }

        /** @return mixed */
        public function getMessageManager()
        {
            return null;
        }
    }
}

namespace Magento\Backend\Model {
    class Auth
    {
    }
}

namespace Magento\Framework\Math {
    class Random
    {
        public function getRandomString(int $length): string
        {
            return str_repeat('x', $length);
        }
    }
}

namespace Magento\User\Model {
    class UserFactory
    {
        public function create(array $data = []): User
        {
            return new User();
        }
    }

    class User
    {
        /** @return mixed */
        public function getId()
        {
            return null;
        }

        public function getUsername(): string
        {
            return '';
        }

        /** @return mixed */
        public function getIsActive()
        {
            return false;
        }

        /** @return mixed */
        public function hasAssigned2Role($id)
        {
            return false;
        }

        /** @return static */
        public function loadByUsername(string $username)
        {
            return $this;
        }
    }
}

namespace Magento\User\Model\ResourceModel {
    class User
    {
        public function load($model, $value, $field = null): void
        {
        }

        public function save($model): void
        {
        }

        public function recordLogin($model): void
        {
        }
    }
}

namespace Magento\User\Model\ResourceModel\User {
    class CollectionFactory
    {
        public function create(): Collection
        {
            return new Collection();
        }
    }

    class Collection
    {
        /** @return static */
        public function addFieldToFilter($field, $condition = null)
        {
            return $this;
        }

        public function getSize(): int
        {
            return 0;
        }

        /** @return mixed */
        public function getFirstItem()
        {
            return null;
        }
    }
}
