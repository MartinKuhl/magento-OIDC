<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * GraphQL resolver: oidcLoginUrl (FEAT-08).
 *
 * Returns the SP-initiated login URL for the requested OIDC provider.
 * When no provider_id is supplied the first active customer-facing provider
 * is used as a sensible default (Hyva / headless single-IdP deployments).
 *
 * Schema:
 *   oidcLoginUrl(provider_id: Int): OidcLoginUrlOutput
 *
 * OidcLoginUrlOutput {
 *   url: String!       — SP-initiated URL (includes provider_id= parameter)
 *   label: String      — Configured button label
 *   provider_id: Int   — Resolved provider database ID
 * }
 */
class OidcLoginUrl implements ResolverInterface
{
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
     * @throws GraphQlInputException       When provider_id is provided but non-positive
     * @throws GraphQlNoSuchEntityException When the requested provider does not exist
     */
    #[\Override]
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ): array {
        $providerId = isset($args['provider_id']) ? (int) $args['provider_id'] : null;

        if ($providerId !== null && $providerId <= 0) {
            throw new GraphQlInputException(__('provider_id must be a positive integer.'));
        }

        if ($providerId !== null) {
            // Explicit provider requested
            $provider = $this->oauthUtility->getClientDetailsById($providerId);
            if ($provider === null) {
                throw new GraphQlNoSuchEntityException(
                    __('OIDC provider with ID %1 was not found.', $providerId)
                );
            }
        } else {
            // Default: first active customer provider (sorted by sort_order)
            $providers = $this->oauthUtility->getAllActiveProviders('customer');
            if ($providers === []) {
                // Fall back to legacy single-provider mode: return getSPInitiatedUrl
                $legacyUrl = $this->oauthUtility->getSPInitiatedUrl();
                return [
                    'url'         => $legacyUrl,
                    'label'       => null,
                    'provider_id' => null,
                ];
            }
            $provider   = reset($providers);
            $providerId = (int) ($provider['id'] ?? 0);
        }

        $loginUrl = $this->oauthUtility->getSPInitiatedUrlForProvider($providerId);
        $label    = empty($provider['button_label'])
            ? null
            : (string) $provider['button_label'];

        return [
            'url'         => $loginUrl,
            'label'       => $label,
            'provider_id' => $providerId,
        ];
    }
}
