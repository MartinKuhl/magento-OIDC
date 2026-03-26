<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Model\Provider;

use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory;
use M2Oidc\OAuth\Model\ResourceModel\OidcProviderRepository;

/**
 * Resolves the active OIDC provider for the current request.
 *
 * Extracted from OAuthUtility to give a focused, injectable provider-resolution API.
 * Maintains a per-instance cache of the active provider row to avoid repeated DB queries.
 */
class ProviderResolver
{
    /**
     * Active provider ID for the current request.
     * Set via setActiveProviderId() in the controller/action execute() method.
     */
    private ?int $activeProviderId = null;

    /**
     * Cached provider row — invalidated when activeProviderId changes.
     * Ensures at most one DB query per request.
     *
     * @var array<string,mixed>|null
     */
    private ?array $activeProviderCache = null;

    /**
     * @param CollectionFactory      $clientCollectionFactory Collection factory for provider records
     * @param OidcProviderRepository $providerRepository      Repository for provider lookup by ID
     */
    public function __construct(
        private readonly CollectionFactory $clientCollectionFactory,
        private readonly OidcProviderRepository $providerRepository
    ) {
    }

    /**
     * Set the active provider context for this request.
     *
     * Must be called once per request (e.g. in execute()) before any
     * config resolution call that reads provider-specific values.
     *
     * @param int $providerId Row `id` from m2oidc_oauth_client_apps (> 0)
     */
    public function setActiveProviderId(int $providerId): void
    {
        if ($this->activeProviderId !== $providerId) {
            $this->activeProviderId = $providerId;
            $this->activeProviderCache = null;
        }
    }

    /**
     * Return the currently active provider ID (or null if not set).
     */
    public function getActiveProviderId(): ?int
    {
        return $this->activeProviderId;
    }

    /**
     * Lazy-load and cache the active provider row.
     *
     * Resolution order:
     *  1. Explicit provider_id set via setActiveProviderId()
     *  2. First active provider in the table (single-provider / legacy fallback)
     *
     * @return array<string,mixed> Provider data array or empty array if none found
     */
    public function resolveActiveProvider(): array
    {
        if ($this->activeProviderCache !== null) {
            return $this->activeProviderCache;
        }

        if ($this->activeProviderId !== null && $this->activeProviderId > 0) {
            $this->activeProviderCache = $this->providerRepository->getClientDetailsById($this->activeProviderId) ?: [];
            return $this->activeProviderCache;
        }

        // Fallback: first active provider (covers single-provider installations)
        $providers = $this->getAllActiveProviders();
        $this->activeProviderCache = $providers === [] ? [] : reset($providers);
        return $this->activeProviderCache;
    }

    /**
     * Return all active providers for the given login type, ordered by sort_order.
     *
     * @param  string $loginType 'customer' | 'admin' | 'both'
     * @return array<int, array<string, mixed>>
     */
    public function getAllActiveProviders(string $loginType = 'customer'): array
    {
        $collection = $this->clientCollectionFactory->create();
        $collection->addFieldToFilter('is_active', ['eq' => 1]);
        $providers = [];

        foreach ($collection as $item) {
            $data = $item->getData();
            $providerLoginType = $data['login_type'] ?? 'both';

            if (in_array($providerLoginType, [$loginType, 'both', ''], true)) {
                $providers[] = $data;
            }
        }

        usort($providers, function (array $a, array $b): int {
            return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        });

        return $providers;
    }
}
