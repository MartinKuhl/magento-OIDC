<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Attribute;

/**
 * Strategy interface for mapping flattened OIDC claims to Magento attribute values.
 *
 * Third-party modules can replace the default mapping logic by declaring a DI
 * preference for the concrete implementation class:
 *
 *   <preference for="MiniOrange\OAuth\Model\Attribute\CustomerAttributeMapper"
 *               type="Vendor\Module\Model\Attribute\CustomCustomerMapper"/>
 */
interface AttributeMapperInterface
{
    /**
     * Map flattened OIDC claims to Magento attribute key => value pairs.
     *
     * Implementations must omit keys whose resolved value is null or empty string
     * so that callers can use isset() to determine whether a value was found.
     *
     * Internal keys in $mappingConfig are prefixed with an underscore (e.g. '_email',
     * '_raw_attrs') and are not OIDC claim names.
     *
     * @param  array<string,mixed>  $flattenedAttrs Flattened OIDC claims (dot-notation keys)
     * @param  array<string,mixed>  $mappingConfig  Attribute type => claim name, plus internal keys
     * @return array<string,mixed>  Magento attribute key => resolved value (null/'' values omitted)
     */
    public function map(array $flattenedAttrs, array $mappingConfig): array;
}
