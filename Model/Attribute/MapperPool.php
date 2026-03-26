<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Attribute;

/**
 * Registry of per-provider attribute mapper implementations.
 *
 * Third-party modules can inject custom mappers via etc/di.xml by adding entries
 * to the $mappers array using one of the two key conventions:
 *
 *   "{providerId}_{type}"   — maps a specific provider ID and type, e.g. "3_admin"
 *   "default_{type}"        — maps all providers of a type, e.g. "default_customer"
 *
 * Resolution order: provider-specific → type default.
 *
 * Example di.xml override:
 * <type name="M2Oidc\OAuth\Model\Attribute\MapperPool">
 *     <arguments>
 *         <argument name="mappers" xsi:type="array">
 *             <item name="default_admin"    xsi:type="object">Vendor\Module\Attribute\MyAdminMapper</item>
 *             <item name="5_customer"       xsi:type="object">Vendor\Module\Attribute\AcmeCustomerMapper</item>
 *         </argument>
 *     </arguments>
 * </type>
 */
class MapperPool
{
    /** @var array<string, AttributeMapperInterface> */
    private array $mappers;

    /**
     * @param mixed[] $mappers Mapper registry keyed by "{providerId}_{type}" or "default_{type}"
     */
    public function __construct(array $mappers = [])
    {
        $this->mappers = $mappers;
    }

    /**
     * Resolve the mapper for the given provider and type.
     *
     * @param  int    $providerId Provider ID from m2oidc_oauth_client_apps
     * @param  string $type       Mapper type: 'admin' or 'customer'
     * @throws \InvalidArgumentException When no mapper is registered for the type
     */
    public function getMapper(int $providerId, string $type): AttributeMapperInterface
    {
        // 1. Provider-specific override
        $specificKey = $providerId . '_' . $type;
        if (isset($this->mappers[$specificKey])) {
            return $this->mappers[$specificKey];
        }

        // 2. Type-level default
        $defaultKey = 'default_' . $type;
        if (isset($this->mappers[$defaultKey])) {
            return $this->mappers[$defaultKey];
        }

        throw new \InvalidArgumentException(sprintf(
            'No attribute mapper registered for type "%s" (provider %d). '
            . 'Register a mapper via etc/di.xml using key "%s" or "%s".',
            $type,
            $providerId,
            $specificKey,
            $defaultKey
        ));
    }
}
