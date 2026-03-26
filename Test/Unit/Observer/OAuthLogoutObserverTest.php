<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\UrlInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Observer\OAuthLogoutObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OAuthLogoutObserver.
 *
 * Verifies:
 *  - Non-OIDC sessions are skipped (no redirect, no revocation)
 *  - Unknown provider_id causes no redirect
 *  - The logout-guard cookie is set before redirect
 *  - Standard OIDC end_session URL is built correctly with id_token_hint
 *  - Authelia detection yields a ?rd= URL instead of standard OIDC params
 *
 * @covers \M2Oidc\OAuth\Observer\OAuthLogoutObserver
 */
class OAuthLogoutObserverTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var HttpResponse&MockObject */
    private HttpResponse $response;

    /** @var CookieManagerInterface&MockObject */
    private CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory&MockObject */
    private CookieMetadataFactory $cookieMetadataFactory;

    /** @var CustomerSession&MockObject */
    private CustomerSession $customerSession;

    /** @var UrlInterface&MockObject */
    private UrlInterface $url;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var Observer */
    private Observer $observer;

    protected function setUp(): void
    {
        $this->oauthUtility          = $this->createMock(OAuthUtility::class);
        // Use HttpResponse mock so the instanceof check passes and setRedirect() is mockable
        $this->response              = $this->createMock(HttpResponse::class);
        $this->response->method('sendResponse'); // prevent exit(0) side-effect
        $this->cookieManager         = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $this->createMock(CookieMetadataFactory::class);
        $this->customerSession       = $this->createMock(CustomerSession::class);
        $this->url                   = $this->createMock(UrlInterface::class);
        $this->curlFactory           = $this->createMock(CurlFactory::class);
        $this->observer              = new Observer([]);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('customlogContext');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildObserver(): OAuthLogoutObserver
    {
        return new OAuthLogoutObserver(
            $this->oauthUtility,
            $this->response,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->customerSession,
            $this->url,
            $this->curlFactory
        );
    }

    /**
     * Configure a PublicCookieMetadata fluent mock that is returned by
     * CookieMetadataFactory::createPublicCookieMetadata().
     */
    private function stubCookieMetadata(): PublicCookieMetadata&MockObject
    {
        $meta = $this->createMock(PublicCookieMetadata::class);
        $meta->method('setPath')->willReturnSelf();
        $meta->method('setHttpOnly')->willReturnSelf();
        $meta->method('setSecure')->willReturnSelf();
        $meta->method('setDuration')->willReturnSelf();
        $meta->method('setSameSite')->willReturnSelf();

        $this->cookieMetadataFactory
            ->method('createPublicCookieMetadata')
            ->willReturn($meta);

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Test 1 – Non-OIDC session: no id_token and no provider_id
    // -------------------------------------------------------------------------

    public function testNoOpWhenCustomerSessionHasNoIdToken(): void
    {
        // No id_token, no provider_id → should early-return without any cookie or redirect
        $this->customerSession->method('getData')->willReturnMap([
            ['oidc_id_token',    false, ''],
            ['oidc_provider_id', false, 0],
        ]);

        // Cookie manager should NOT be asked to set the logout-guard cookie
        $this->cookieManager->expects($this->never())->method('setPublicCookie');

        // Response should NOT receive a redirect
        $this->response->expects($this->never())->method('setRedirect');

        $observer = $this->buildObserver();
        $observer->execute($this->observer);
    }

    // -------------------------------------------------------------------------
    // Test 2 – Provider not found: provider_id is set but getClientDetailsById returns null
    // -------------------------------------------------------------------------

    public function testNoOpWhenProviderNotFound(): void
    {
        $this->customerSession->method('getData')->willReturnMap([
            ['oidc_id_token',    false, 'some.id.token'],
            ['oidc_provider_id', false, 42],
            ['oidc_access_token', false, ''],
        ]);

        // Provider lookup returns null → no endsession_endpoint
        $this->oauthUtility->method('getClientDetailsById')->with(42)->willReturn(null);

        // Fallback store-config also returns empty
        $this->oauthUtility->method('getStoreConfig')
            ->with(OAuthConstants::OAUTH_LOGOUT_URL)
            ->willReturn('');

        // No redirect expected
        $this->response->expects($this->never())->method('setRedirect');

        $observer = $this->buildObserver();
        $observer->execute($this->observer);
    }

    // -------------------------------------------------------------------------
    // Test 3 – Logout-guard cookie is set before redirect
    // -------------------------------------------------------------------------

    public function testSetsLogoutGuardCookieBeforeRedirect(): void
    {
        $this->customerSession->method('getData')->willReturnMap([
            ['oidc_id_token',    false, 'header.payload.sig'],
            ['oidc_provider_id', false, 5],
            ['oidc_access_token', false, ''],
        ]);

        $this->oauthUtility->method('getClientDetailsById')->with(5)->willReturn([
            'endsession_endpoint' => 'https://idp.example.com/connect/endsession',
            'revocation_endpoint' => '',
        ]);

        $this->stubCookieMetadata();

        $this->url->method('getUrl')->willReturn('https://store.example.com/m2oidc/actions/postlogout');

        // The guard cookie MUST be set before the redirect
        $cookieWasSet = false;
        $this->cookieManager->method('setPublicCookie')
            ->willReturnCallback(function (string $name) use (&$cookieWasSet): void {
                if ($name === 'oidc_logout_guard') {
                    $cookieWasSet = true;
                }
            });

        // Use a plain ResponseInterface mock so the instanceof HttpResponse check fails,
        // avoiding the exit(0) branch — cookie is still set before that block.
        $plainResponse = $this->createMock(ResponseInterface::class);

        $observer = new OAuthLogoutObserver(
            $this->oauthUtility,
            $plainResponse,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->customerSession,
            $this->url,
            $this->curlFactory
        );
        $observer->execute($this->observer);

        $this->assertTrue($cookieWasSet, 'oidc_logout_guard cookie must be set before redirect');
    }

    // -------------------------------------------------------------------------
    // Test 4 – Standard OIDC end_session URL built correctly
    // -------------------------------------------------------------------------

    public function testBuildsCorrectEndSessionUrl(): void
    {
        $idToken    = 'eyJhbGciOiJSUzI1NiJ9.payload.sig';
        $endSession = 'https://idp.example.com/connect/endsession';

        $this->customerSession->method('getData')->willReturnMap([
            ['oidc_id_token',    false, $idToken],
            ['oidc_provider_id', false, 7],
            ['oidc_access_token', false, ''],
        ]);

        $this->oauthUtility->method('getClientDetailsById')->with(7)->willReturn([
            'endsession_endpoint' => $endSession,
            'revocation_endpoint' => '',
        ]);

        $postLogoutUrl = 'https://store.example.com/m2oidc/actions/postlogout';
        $this->url->method('getUrl')->willReturn($postLogoutUrl);

        $this->stubCookieMetadata();

        // Use an HttpResponse mock so the redirect path is exercised.
        // setRedirect() throws to interrupt execution before exit(0) is reached;
        // the thrown exception is caught below so assertions still run.
        $httpResponse = $this->createMock(HttpResponse::class);

        $capturedUrl = null;
        $httpResponse->expects($this->once())
            ->method('setRedirect')
            ->willReturnCallback(function (string $url) use (&$capturedUrl): void {
                $capturedUrl = $url;
                throw new \RuntimeException('redirect_intercepted');
            });

        $observer = new OAuthLogoutObserver(
            $this->oauthUtility,
            $httpResponse,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->customerSession,
            $this->url,
            $this->curlFactory
        );

        try {
            $observer->execute($this->observer);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'redirect_intercepted') {
                throw $e;
            }
        }

        $this->assertNotNull($capturedUrl, 'setRedirect must be called');
        $this->assertStringContainsString('id_token_hint=' . urlencode($idToken), (string) $capturedUrl);
        $this->assertStringContainsString('post_logout_redirect_uri=', (string) $capturedUrl);
        $this->assertStringStartsWith($endSession, (string) $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Test 5 – Authelia-style endpoint uses ?rd= instead of standard OIDC params
    // -------------------------------------------------------------------------

    public function testAutheliaDetectionUsesRdParam(): void
    {
        $idToken    = 'some.jwt.token';
        $endSession = 'https://auth.example.com/logout';

        $this->customerSession->method('getData')->willReturnMap([
            ['oidc_id_token',    false, $idToken],
            ['oidc_provider_id', false, 3],
            ['oidc_access_token', false, ''],
        ]);

        $this->oauthUtility->method('getClientDetailsById')->with(3)->willReturn([
            'endsession_endpoint' => $endSession,
            'revocation_endpoint' => '',
        ]);

        $loginUrl = 'https://store.example.com/customer/account/login/';
        $this->url->method('getUrl')->with('customer/account/login')->willReturn($loginUrl);

        $this->stubCookieMetadata();

        // setRedirect() throws to interrupt execution before exit(0) is reached.
        $httpResponse = $this->createMock(HttpResponse::class);

        $capturedUrl = null;
        $httpResponse->expects($this->once())
            ->method('setRedirect')
            ->willReturnCallback(function (string $url) use (&$capturedUrl): void {
                $capturedUrl = $url;
                throw new \RuntimeException('redirect_intercepted');
            });

        $observer = new OAuthLogoutObserver(
            $this->oauthUtility,
            $httpResponse,
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->customerSession,
            $this->url,
            $this->curlFactory
        );

        try {
            $observer->execute($this->observer);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'redirect_intercepted') {
                throw $e;
            }
        }

        $this->assertNotNull($capturedUrl, 'setRedirect must be called for Authelia mode');
        $this->assertStringContainsString('?rd=', (string) $capturedUrl);
        $this->assertStringNotContainsString('id_token_hint', (string) $capturedUrl);
        $this->assertStringNotContainsString('post_logout_redirect_uri', (string) $capturedUrl);
    }
}
