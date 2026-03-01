<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Controller\Adminhtml\Actions;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use MiniOrange\OAuth\Helper\Curl;
use MiniOrange\OAuth\Helper\OAuthConstants;
use MiniOrange\OAuth\Helper\OAuthUtility;

/**
 * Admin AJAX endpoint: OIDC provider connectivity health-check (FEAT-06).
 *
 * Route:  GET /admin/mooauth/actions/healthcheck
 *
 * Returns JSON:
 * {
 *   "status":        "ok" | "degraded" | "unreachable",
 *   "discovery_ms":  <int>,   // latency for discovery/token endpoint ping
 *   "jwks_reachable": true | false,
 *   "jwks_ms":       <int>,
 *   "provider":      "<app_name>",
 *   "errors":        []        // array of human-readable error strings
 * }
 *
 * This controller extends Backend\App\Action so it is protected by Magento's
 * admin ACL automatically. Only authenticated admins with the OIDC settings ACL
 * resource can call it.
 */
class HealthCheck extends Action implements HttpGetActionInterface
{
    /**
     * ACL resource required to call this endpoint.
     * Maps to MiniOrange_OAuth::oauth_settings in etc/acl.xml.
     */
    public const ADMIN_RESOURCE = 'MiniOrange_OAuth::oauth_settings';

    /** @var OAuthUtility */
    private readonly OAuthUtility $oauthUtility;

    /** @var Curl */
    private readonly Curl $curl;

    /**
     * Initialize health-check controller.
     *
     * @param Context      $context
     * @param OAuthUtility $oauthUtility
     * @param Curl         $curl
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        Curl $curl
    ) {
        $this->oauthUtility = $oauthUtility;
        $this->curl         = $curl;
        parent::__construct($context);
    }

    /**
     * Execute the health-check and return a JSON response.
     */
    #[\Override]
    public function execute(): JsonResult
    {
        $errors   = [];
        $status   = 'ok';

        // Resolve provider to check (default: first configured app)
        $appName      = (string) $this->getRequest()->getParam('app_name', '');
        $clientDetails = $appName !== ''
            ? $this->oauthUtility->getClientDetailsByAppName($appName)
            : null;

        if ($clientDetails === null || $clientDetails === []) {
            $collection    = $this->oauthUtility->getOAuthClientApps();
            $clientDetails = $collection->count() > 0
                ? $collection->getFirstItem()->getData()
                : null;
        }

        if ($clientDetails === null) {
            return $this->buildResponse([
                'status'         => 'unreachable',
                'discovery_ms'   => 0,
                'jwks_reachable' => false,
                'jwks_ms'        => 0,
                'provider'       => '',
                'errors'         => ['No OIDC provider configured.'],
            ]);
        }

        $provider = (string) ($clientDetails['app_name'] ?? '');

        // ── 1. Ping the token endpoint (or discovery URL) ────────────────────
        $discoveryMs   = 0;
        $pingUrl       = (string) ($clientDetails['access_token_endpoint']
            ?? $clientDetails['well_known_config_url']
            ?? '');

        if ($pingUrl !== '') {
            $t0 = hrtime(true);
            try {
                // HEAD-style ping: we only care about TCP reachability + HTTP response, not the body.
                $this->curl->sendUserInfoRequest($pingUrl, []);
            } catch (\Throwable $e) {
                $errors[] = 'Token/discovery endpoint unreachable: ' . $e->getMessage();
                $status   = 'unreachable';
            }
            $discoveryMs = (int) round((hrtime(true) - $t0) / 1_000_000);
        } else {
            $errors[] = 'Token endpoint URL not configured.';
            $status   = 'degraded';
        }

        // ── 2. Check JWKS endpoint ────────────────────────────────────────────
        $jwksReachable = false;
        $jwksMs        = 0;
        $jwksUrl       = (string) ($clientDetails['jwks_endpoint'] ?? '');

        if ($jwksUrl !== '') {
            $t1 = hrtime(true);
            try {
                $jwksBody = $this->curl->sendUserInfoRequest($jwksUrl, []);
                $jwksData = json_decode($jwksBody, true);
                $jwksReachable = is_array($jwksData) && isset($jwksData['keys']);
                if (!$jwksReachable) {
                    $errors[] = 'JWKS endpoint returned invalid JSON or missing "keys" field.';
                    if ($status === 'ok') {
                        $status = 'degraded';
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = 'JWKS endpoint unreachable: ' . $e->getMessage();
                if ($status === 'ok') {
                    $status = 'degraded';
                }
            }
            $jwksMs = (int) round((hrtime(true) - $t1) / 1_000_000);
        } else {
            $errors[] = 'JWKS endpoint URL not configured.';
            if ($status === 'ok') {
                $status = 'degraded';
            }
        }

        $this->oauthUtility->customlogContext('oidc.health_check', [
            'provider'       => $provider,
            'status'         => $status,
            'discovery_ms'   => $discoveryMs,
            'jwks_reachable' => $jwksReachable,
            'jwks_ms'        => $jwksMs,
        ]);

        return $this->buildResponse([
            'status'         => $status,
            'discovery_ms'   => $discoveryMs,
            'jwks_reachable' => $jwksReachable,
            'jwks_ms'        => $jwksMs,
            'provider'       => $provider,
            'errors'         => $errors,
        ]);
    }

    /**
     * Build a JSON result object.
     *
     * @param  array $data
     */
    private function buildResponse(array $data): JsonResult
    {
        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData($data);
        return $result;
    }
}
