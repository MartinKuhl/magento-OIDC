<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Health;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Lightweight frontend health-check for monitoring systems.
 *
 * Route:  GET /mooauth/health/check
 *
 * Returns JSON:
 * {
 *   "status":    "ok" | "error",
 *   "providers": [
 *     {"id": 1, "name": "MyIdP", "configured": true}
 *   ]
 * }
 *
 * This is a read-only, unauthenticated endpoint intended for uptime monitors
 * (e.g., Pingdom, AWS Route 53 health checks). It does NOT make outbound HTTP
 * calls to avoid SSRF exposure; use the admin health-check for live reachability
 * tests: GET /admin/mooauth/actions/healthcheck
 */
class Check implements HttpGetActionInterface
{
    /**
     * Initialize health check controller.
     *
     * @param OAuthUtility  $oauthUtility
     * @param ResultFactory $resultFactory
     */
    public function __construct(
        private readonly OAuthUtility $oauthUtility,
        private readonly ResultFactory $resultFactory,
    ) {
    }

    /**
     * Execute the health check and return a JSON response.
     */
    #[\Override]
    public function execute(): JsonResult
    {
        $providers = [];
        $hasActive = false;

        try {
            $collection = $this->oauthUtility->getOAuthClientApps();
            foreach ($collection as $app) {
                $data      = $app->getData();
                $active    = (bool) ($data['is_active'] ?? false);
                $configured = !empty($data['clientID'])
                    && !empty($data['access_token_endpoint']);

                if ($active && $configured) {
                    $hasActive = true;
                }

                $providers[] = [
                    'id'         => (int) ($data['id'] ?? 0),
                    'name'       => (string) ($data['display_name'] ?? $data['app_name'] ?? ''),
                    'active'     => $active,
                    'configured' => $configured,
                ];
            }
        } catch (\Throwable) {
            // Non-fatal: return error status
            $providers = [];
            $hasActive = false;
        }

        $status = ($hasActive || $providers === []) ? 'ok' : 'error';
        if ($providers !== [] && !$hasActive) {
            $status = 'error';
        }

        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData([
            'status'    => $status,
            'providers' => $providers,
        ]);
        return $result;
    }
}
