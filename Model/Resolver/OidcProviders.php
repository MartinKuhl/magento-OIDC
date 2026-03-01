<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * GraphQL resolver: oidcProviders (FEAT-08).
 *
 * Returns all active OIDC providers for the requested login_type, together
 * with the SP-initiated login URL for each. Headless / Hyva storefronts use
 * this to dynamically render a list of SSO login buttons without server-side
 * phtml rendering.
 *
 * Schema:
 *   oidcProviders(login_type: String): [OidcProviderOutput]
 *
 * OidcProviderOutput {
 *   id: Int!
 *   display_name: String
 *   button_label: String
 *   button_color: String
 *   login_url: String!
 * }
 *
 * login_type values: "customer" (default), "admin", "both"
 */
class OidcProviders implements ResolverInterface
{
    /** Allowed login_type filter values. */
    private const ALLOWED_LOGIN_TYPES = ['customer', 'admin', 'both'];

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /**
     * @param OAuthUtility $oauthUtility
     */
    public function __construct(OAuthUtility $oauthUtility)
    {
        $this->oauthUtility = $oauthUtility;
    }

    /**
     * @inheritdoc
     *
     * @param  Field       $field
     * @param  mixed       $context
     * @param  ResolveInfo $info
     * @param  array|null  $value
     * @param  array|null  $args
     * @throws GraphQlInputException When an invalid login_type is supplied
     */
    #[\Override]
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $loginType = isset($args['login_type']) ? (string) $args['login_type'] : 'customer';

        if (!in_array($loginType, self::ALLOWED_LOGIN_TYPES, true)) {
            throw new GraphQlInputException(
                __(
                    'Invalid login_type "%1". Allowed values: %2.',
                    $loginType,
                    implode(', ', self::ALLOWED_LOGIN_TYPES)
                )
            );
        }

        $providers = $this->oauthUtility->getAllActiveProviders($loginType);

        $result = [];
        foreach ($providers as $provider) {
            $pid      = (int) ($provider['id'] ?? 0);
            $loginUrl = $this->oauthUtility->getSPInitiatedUrlForProvider($pid);
            $color    = (string) ($provider['button_color'] ?? '');
            $result[] = [
                'id'           => $pid,
                'display_name' => empty($provider['display_name'])
                    ? null
                    : (string) $provider['display_name'],
                'button_label' => empty($provider['button_label'])
                    ? null
                    : (string) $provider['button_label'],
                'button_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null,
                'login_url'    => $loginUrl,
            ];
        }

        return $result;
    }
}
