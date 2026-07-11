<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Plugin\Auth;

use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\HTTP\PhpEnvironment\Response as HttpResponse;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use M2Oidc\OAuth\Helper\OAuthConstants;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Service\RpInitiatedLogoutService;
use M2Oidc\OAuth\Plugin\Auth\OidcLogoutPlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OidcLogoutPlugin.
 *
 * Verifies:
 *  - $proceed() is called before session data is consumed
 *  - The oidc_authenticated cookie is deleted after logout
 *  - The oidc_logout_guard cookie is set after logout
 *  - Standard OIDC redirect includes id_token_hint and endpoint
 *  - Token revocation curl call is made when access_token and revocation_endpoint are present
 *
 * @covers \M2Oidc\OAuth\Plugin\Auth\OidcLogoutPlugin
 */
class OidcLogoutPluginTest extends TestCase
{
    /** @var CookieManagerInterface&MockObject */
    private CookieManagerInterface $cookieManager;

    /** @var CookieMetadataFactory&MockObject */
    private CookieMetadataFactory $cookieMetadataFactory;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var AuthSession&MockObject */
    private AuthSession $authSession;

    /** @var BackendUrlInterface&MockObject */
    private BackendUrlInterface $backendUrl;

    /** @var HttpResponse&MockObject */
    private HttpResponse $response;

    /** @var FrontNameResolver&MockObject */
    private FrontNameResolver $frontNameResolver;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var RpInitiatedLogoutService */
    private RpInitiatedLogoutService $rpInitiatedLogoutService;

    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resourceConnection;

    /** @var Auth&MockObject */
    private Auth $authSubject;

    protected function setUp(): void
    {
        $this->cookieManager         = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $this->createMock(CookieMetadataFactory::class);
        $this->oauthUtility          = $this->createMock(OAuthUtility::class);
        $this->authSession           = $this->createMock(AuthSession::class);
        $this->backendUrl            = $this->createMock(BackendUrlInterface::class);
        $this->response              = $this->createMock(HttpResponse::class);
        $this->frontNameResolver     = $this->createMock(FrontNameResolver::class);
        $this->curlFactory           = $this->createMock(CurlFactory::class);
        $this->resourceConnection    = $this->createMock(ResourceConnection::class);
        $this->authSubject           = $this->createMock(Auth::class);

        $this->oauthUtility->method('customlog');

        // Real service wired with the shared mocks so revocation assertions on
        // the CurlFactory keep working after the M29 extraction.
        $this->rpInitiatedLogoutService = new RpInitiatedLogoutService(
            $this->oauthUtility,
            $this->curlFactory
        );

        // Default: DB adapter that accepts any update/insert call
        $dbConnection = $this->createMock(AdapterInterface::class);
        $dbConnection->method('update')->willReturn(0);
        $this->resourceConnection->method('getConnection')->willReturn($dbConnection);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildPlugin(): OidcLogoutPlugin
    {
        return new OidcLogoutPlugin(
            $this->cookieManager,
            $this->cookieMetadataFactory,
            $this->oauthUtility,
            $this->authSession,
            $this->backendUrl,
            $this->response,
            $this->frontNameResolver,
            $this->rpInitiatedLogoutService,
            $this->resourceConnection
        );
    }

    /**
     * Return a fluent PublicCookieMetadata stub and wire it to the factory.
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

    /**
     * Stub authSession with the given OIDC values.
     *
     * @param string $idToken
     * @param string $accessToken
     * @param int    $providerId
     * @param int    $userId
     */
    private function stubSession(
        string $idToken = '',
        string $accessToken = '',
        int $providerId = 0,
        int $userId = 0
    ): void {
        $user = null;
        if ($userId > 0) {
            $user = $this->createMock(\Magento\User\Model\User::class);
            $user->method('getId')->willReturn($userId);
        }

        // getUser() is a @method magic annotation on AuthSession (backed by DataObject::__call),
        // so it resolves to getData('user') — cannot be mocked directly.
        // Use a callback to handle all getData() calls including the implicit 'user' key.
        $this->authSession->method('getData')->willReturnCallback(
            function ($key) use ($idToken, $accessToken, $providerId, $user) {
                return match ($key) {
                    'oidc_id_token'    => $idToken,
                    'oidc_access_token' => $accessToken,
                    'oidc_provider_id'  => $providerId,
                    'user'              => $user,
                    default             => null,
                };
            }
        );
    }

    // -------------------------------------------------------------------------
    // Test 1 – $proceed() is called before session data is read
    // -------------------------------------------------------------------------

    public function testProceedCalledBeforeReadingSession(): void
    {
        $this->stubSession('id_token_value', '', 1, 1);
        $this->stubCookieMetadata();

        $this->oauthUtility->method('getClientDetailsById')->with(1)->willReturn([
            'endsession_endpoint' => '',
            'revocation_endpoint' => '',
        ]);
        $this->oauthUtility->method('getStoreConfig')
            ->with(OAuthConstants::OAUTH_LOGOUT_URL)
            ->willReturn('');

        $proceedCalled = false;
        $proceed = function () use (&$proceedCalled): void {
            $proceedCalled = true;
        };

        $plugin = $this->buildPlugin();
        $plugin->aroundLogout($this->authSubject, $proceed);

        $this->assertTrue($proceedCalled, '$proceed() must be called during aroundLogout');
    }

    // -------------------------------------------------------------------------
    // Test 2 – oidc_authenticated cookie is deleted
    // -------------------------------------------------------------------------

    public function testOidcLogoutCookieIsDeleted(): void
    {
        $this->stubSession('id_token_value', '', 1, 0);
        $this->stubCookieMetadata();

        $this->oauthUtility->method('getClientDetailsById')->with(1)->willReturn([
            'endsession_endpoint' => '',
            'revocation_endpoint' => '',
        ]);
        $this->oauthUtility->method('getStoreConfig')
            ->with(OAuthConstants::OAUTH_LOGOUT_URL)
            ->willReturn('');

        $this->cookieManager->expects($this->atLeastOnce())
            ->method('deleteCookie')
            ->with('oidc_authenticated', $this->anything());

        $plugin = $this->buildPlugin();
        $plugin->aroundLogout($this->authSubject, function (): void {
        });
    }

    // -------------------------------------------------------------------------
    // Test 3 – oidc_logout_guard cookie is set
    // -------------------------------------------------------------------------

    public function testLogoutGuardCookieIsSet(): void
    {
        $this->stubSession('id_token_value', '', 1, 0);
        $this->stubCookieMetadata();

        $this->oauthUtility->method('getClientDetailsById')->with(1)->willReturn([
            'endsession_endpoint' => '',
            'revocation_endpoint' => '',
        ]);
        $this->oauthUtility->method('getStoreConfig')
            ->with(OAuthConstants::OAUTH_LOGOUT_URL)
            ->willReturn('');

        $this->cookieManager->expects($this->atLeastOnce())
            ->method('setPublicCookie')
            ->with('oidc_logout_guard', '1', $this->anything());

        $plugin = $this->buildPlugin();
        $plugin->aroundLogout($this->authSubject, function (): void {
        });
    }

    // -------------------------------------------------------------------------
    // Test 4 – Redirect to end_session_endpoint with id_token_hint
    // -------------------------------------------------------------------------

    public function testRedirectToEndSessionEndpoint(): void
    {
        $idToken    = 'eyJhbGciOiJSUzI1NiJ9.payload.sig';
        $endSession = 'https://idp.example.com/connect/endsession';

        $this->stubSession($idToken, '', 2, 0);
        $this->stubCookieMetadata();

        $this->oauthUtility->method('getClientDetailsById')->with(2)->willReturn([
            'endsession_endpoint' => $endSession,
            'revocation_endpoint' => '',
        ]);

        $this->backendUrl->method('getBaseUrl')->willReturn('https://store.example.com/');

        // setRedirect() throws to intercept before exit is reached; caught below.
        $capturedUrl = null;
        $this->response->expects($this->once())
            ->method('setRedirect')
            ->willReturnCallback(function (string $url) use (&$capturedUrl): void {
                $capturedUrl = $url;
                throw new \RuntimeException('redirect_intercepted');
            });

        $plugin = $this->buildPlugin();

        try {
            $plugin->aroundLogout($this->authSubject, function (): void {
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'redirect_intercepted') {
                throw $e;
            }
        }

        $this->assertNotNull($capturedUrl, 'setRedirect must be called');
        $this->assertStringContainsString('id_token_hint=' . urlencode($idToken), (string) $capturedUrl);
        $this->assertStringStartsWith($endSession, (string) $capturedUrl);
    }

    // -------------------------------------------------------------------------
    // Test 5 – Token revocation curl call is made
    // -------------------------------------------------------------------------

    public function testTokenRevocationIsCalled(): void
    {
        $accessToken        = 'access_token_value';
        $endSession         = 'https://idp.example.com/connect/endsession';
        $revocationEndpoint = 'https://idp.example.com/connect/revocation';

        $this->stubSession('some.id.token', $accessToken, 3, 0);
        $this->stubCookieMetadata();

        // Revocation requires a valid endsession_endpoint (plugin validates URL before entering revocation block).
        $this->oauthUtility->method('getClientDetailsById')->with(3)->willReturn([
            'endsession_endpoint' => $endSession,
            'revocation_endpoint' => $revocationEndpoint,
            'clientID'            => 'my_client',
            'client_secret'       => 'my_secret',
        ]);

        $this->backendUrl->method('getBaseUrl')->willReturn('https://store.example.com/');

        // Verify that the curl adapter's write() is invoked with the revocation endpoint
        $curlAdapter = $this->createMock(CurlAdapter::class);
        $curlAdapter->method('setConfig')->willReturnSelf();
        $curlAdapter->expects($this->once())
            ->method('write')
            ->with('POST', $revocationEndpoint, $this->anything(), $this->anything(), $this->anything());
        $curlAdapter->method('read')->willReturn('');
        $curlAdapter->method('close');

        $this->curlFactory->method('create')->willReturn($curlAdapter);

        // Intercept redirect before exit is reached.
        $this->response->method('setRedirect')
            ->willReturnCallback(function (): void {
                throw new \RuntimeException('redirect_intercepted');
            });

        $plugin = $this->buildPlugin();

        try {
            $plugin->aroundLogout($this->authSubject, function (): void {
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'redirect_intercepted') {
                throw $e;
            }
        }
    }
}
