<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Controller\Actions;

use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\UrlInterface;
use M2Oidc\OAuth\Helper\OAuthUtility;

/**
 * Unified Post Logout Redirect URI handler.
 *
 * Route: GET /m2oidc/actions/postlogout
 *
 * When an OIDC provider only allows registering a single Post Logout
 * Redirect URI, both the admin and customer logout flows point here.
 * The context (admin vs. customer) is encoded in the OIDC `state`
 * parameter that the IdP echoes back verbatim:
 *
 *   admin:<16-byte-hex>    → redirect to admin login page
 *   customer:<16-byte-hex> → redirect to customer/account/login
 *   (absent / unknown)     → redirect to store home (safe fallback)
 *
 * Register with your OIDC provider:
 *   https://your-site.com/m2oidc/actions/postlogout
 *
 * Authelia (/logout?rd=…) is unaffected — it never calls this endpoint.
 *
 * @package M2Oidc\OAuth\Controller\Actions
 */
class PostLogoutCallback extends BaseAction implements HttpGetActionInterface
{
    /** @var FrontNameResolver */
    private readonly FrontNameResolver $frontNameResolver;

    /** @var BackendUrlInterface */
    private readonly BackendUrlInterface $backendUrl;

    /** @var UrlInterface */
    private readonly UrlInterface $url;

    /**
     * @param Context            $context
     * @param OAuthUtility       $oauthUtility
     * @param FrontNameResolver  $frontNameResolver
     * @param BackendUrlInterface $backendUrl
     * @param UrlInterface       $url
     */
    public function __construct(
        Context $context,
        OAuthUtility $oauthUtility,
        FrontNameResolver $frontNameResolver,
        BackendUrlInterface $backendUrl,
        UrlInterface $url
    ) {
        $this->frontNameResolver = $frontNameResolver;
        $this->backendUrl        = $backendUrl;
        $this->url               = $url;
        parent::__construct($context, $oauthUtility);
    }

    /**
     * Determine redirect destination from the `state` parameter and redirect.
     */
    #[\Override]
    public function execute(): Redirect
    {
        $state   = (string) $this->getRequest()->getParam('state', '');
        $context = $this->parseContext($state);

        $this->oauthUtility->customlog(
            "PostLogoutCallback: state={$state}, context={$context}"
        );

        $destination = match ($context) {
            'admin'    => $this->resolveAdminLoginUrl(),
            'customer' => $this->url->getUrl('customer/account/login'),
            default    => $this->url->getUrl('/'),
        };

        return $this->resultRedirectFactory->create()->setUrl($destination);
    }

    /**
     * Extract the context prefix from the state parameter.
     *
     * Expected formats:
     *   admin:<hex>    → 'admin'
     *   customer:<hex> → 'customer'
     *   anything else  → 'unknown'
     *
     * @param string $state
     */
    private function parseContext(string $state): string
    {
        if ($state === '') {
            return 'unknown';
        }

        $colonPos = strpos($state, ':');
        if ($colonPos === false) {
            return 'unknown';
        }

        $prefix = substr($state, 0, $colonPos);

        return match ($prefix) {
            'admin', 'customer' => $prefix,
            default             => 'unknown',
        };
    }

    /**
     * Resolve the static admin login URL (no dynamic key/token).
     *
     * Uses the same approach as OidcLogoutPlugin::resolvePostLogoutRedirectUri()
     * to ensure a stable URL that can be registered as a redirect URI.
     */
    private function resolveAdminLoginUrl(): string
    {
        try {
            $frontName = rtrim($this->frontNameResolver->getFrontName(true), '/');
            $baseUrl   = rtrim($this->backendUrl->getBaseUrl(), '/');
            $adminUrl  = $baseUrl . '/' . $frontName . '/';

            if (filter_var($adminUrl, FILTER_VALIDATE_URL)) {
                return $adminUrl;
            }
        } catch (\Exception $e) {
            $this->oauthUtility->customlog(
                'PostLogoutCallback: Could not resolve admin URL: ' . $e->getMessage()
            );
        }

        // Fallback to store home if admin URL cannot be resolved
        return $this->url->getUrl('/');
    }
}
