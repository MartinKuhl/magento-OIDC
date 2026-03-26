<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Controller;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use M2Oidc\OAuth\Controller\Actions\BackChannelLogout;
use M2Oidc\OAuth\Helper\JwtVerifier;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Security\OidcRateLimiter;
use M2Oidc\OAuth\Model\Service\OidcSessionRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BackChannelLogout controller.
 *
 * Verifies:
 *  - Valid logout token results in HTTP 200 and session is destroyed
 *  - Invalid JWT (verification failure) returns HTTP 400
 *  - Rate limit exceeded returns HTTP 429
 *  - Missing logout_token parameter returns HTTP 400
 *
 * @covers \M2Oidc\OAuth\Controller\Actions\BackChannelLogout
 */
class BackChannelLogoutTest extends TestCase
{
    /** @var Context&MockObject */
    private Context $context;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var JsonFactory&MockObject */
    private JsonFactory $jsonFactory;

    /** @var JwtVerifier&MockObject */
    private JwtVerifier $jwtVerifier;

    /** @var OidcSessionRegistry&MockObject */
    private OidcSessionRegistry $sessionRegistry;

    /** @var OidcRateLimiter&MockObject */
    private OidcRateLimiter $rateLimiter;

    /** @var ResourceConnection&MockObject */
    private ResourceConnection $resourceConnection;

    /** @var HttpRequest&MockObject */
    private HttpRequest $request;

    protected function setUp(): void
    {
        $this->oauthUtility      = $this->createMock(OAuthUtility::class);
        $this->jsonFactory       = $this->createMock(JsonFactory::class);
        $this->jwtVerifier       = $this->createMock(JwtVerifier::class);
        $this->sessionRegistry   = $this->createMock(OidcSessionRegistry::class);
        $this->rateLimiter       = $this->createMock(OidcRateLimiter::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->request           = $this->createMock(HttpRequest::class);

        // Build a minimal Context mock that provides a request and redirect factory
        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);

        $resultRedirectFactory = $this->createMock(\Magento\Framework\Controller\Result\RedirectFactory::class);
        $this->context->method('getResultRedirectFactory')->willReturn($resultRedirectFactory);

        $resultFactory = $this->createMock(\Magento\Framework\Controller\ResultFactory::class);
        $this->context->method('getResultFactory')->willReturn($resultFactory);

        $messageManager = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $this->context->method('getMessageManager')->willReturn($messageManager);

        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $this->context->method('getEventManager')->willReturn($eventManager);

        $this->oauthUtility->method('customlog');
        $this->oauthUtility->method('customlogContext');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildController(): BackChannelLogout
    {
        return new BackChannelLogout(
            $this->context,
            $this->oauthUtility,
            $this->jsonFactory,
            $this->jwtVerifier,
            $this->sessionRegistry,
            $this->rateLimiter,
            $this->resourceConnection
        );
    }

    /**
     * Create a Json result mock that captures the HTTP status code.
     *
     * @param int|null $expectedStatus  If provided, assert setHttpResponseCode is called with this value.
     */
    private function makeJsonResult(?int $expectedStatus = null): Json&MockObject
    {
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnSelf();
        if ($expectedStatus !== null) {
            $result->expects($this->once())
                ->method('setHttpResponseCode')
                ->with($expectedStatus)
                ->willReturnSelf();
        } else {
            $result->method('setHttpResponseCode')->willReturnSelf();
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Test 1 – Valid logout token returns 200 and sessions are revoked
    // -------------------------------------------------------------------------

    public function testValidLogoutTokenReturns200(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->request->method('getClientIp')->willReturn('127.0.0.1');
        $this->request->method('getParam')->with('logout_token', '')->willReturn('valid.jwt.token');

        $claims = [
            'iss' => 'https://idp.example.com',
            'aud' => 'my_client_id',
            'sub' => 'user-sub-123',
            'sid' => 'session-sid-456',
        ];
        $this->jwtVerifier->method('decodeWithoutVerification')->willReturn($claims);

        $this->oauthUtility->method('getAllActiveProviders')->willReturn([
            [
                'issuer'        => 'https://idp.example.com',
                'jwks_endpoint' => 'https://idp.example.com/.well-known/jwks.json',
                'clientID'      => 'my_client_id',
            ],
        ]);

        $verifiedClaims = array_merge($claims, [
            'events' => [
                'http://schemas.openid.net/event/backchannel-logout' => (object) [],
            ],
        ]);
        $this->jwtVerifier->method('verifyAndDecode')->willReturn($verifiedClaims);

        $entry = [
            'php_session_id' => 'phpsessidabc123',
            'user_type'      => 'customer',
            'user_id'        => 99,
        ];
        $this->sessionRegistry->method('resolve')->willReturn([$entry]);
        $this->sessionRegistry->expects($this->once())->method('revoke');

        $dbConn = $this->createMock(\Magento\Framework\DB\Adapter\AdapterInterface::class);
        $dbConn->method('update')->willReturn(0);
        $this->resourceConnection->method('getConnection')->willReturn($dbConn);
        $this->resourceConnection->method('getTableName')->willReturnArgument(0);

        // A successful response does NOT call setHttpResponseCode (defaults to 200)
        $jsonResult = $this->createMock(Json::class);
        $jsonResult->method('setData')->willReturnSelf();
        $jsonResult->expects($this->never())->method('setHttpResponseCode');

        $this->jsonFactory->method('create')->willReturn($jsonResult);

        $controller = $this->buildController();
        $result     = $controller->execute();

        $this->assertSame($jsonResult, $result);
    }

    // -------------------------------------------------------------------------
    // Test 2 – JWT verification failure returns 400
    // -------------------------------------------------------------------------

    public function testInvalidJwtReturns400(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->request->method('getClientIp')->willReturn('127.0.0.1');
        $this->request->method('getParam')->with('logout_token', '')->willReturn('bad.jwt.token');

        // decodeWithoutVerification succeeds (to get issuer), but verifyAndDecode fails
        $this->jwtVerifier->method('decodeWithoutVerification')->willReturn([
            'iss' => 'https://idp.example.com',
            'aud' => 'my_client',
        ]);

        $this->oauthUtility->method('getAllActiveProviders')->willReturn([
            [
                'issuer'        => 'https://idp.example.com',
                'jwks_endpoint' => 'https://idp.example.com/jwks',
                'clientID'      => 'my_client',
            ],
        ]);

        $this->jwtVerifier->method('verifyAndDecode')->willReturn(null);

        $jsonResult = $this->makeJsonResult(400);
        $jsonResult->method('setData')->willReturnSelf();
        $this->jsonFactory->method('create')->willReturn($jsonResult);

        $controller = $this->buildController();
        $controller->execute();
    }

    // -------------------------------------------------------------------------
    // Test 3 – Rate limit exceeded returns 429
    // -------------------------------------------------------------------------

    public function testRateLimitExceededReturns429(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(false);
        $this->request->method('getClientIp')->willReturn('1.2.3.4');

        $jsonResult = $this->makeJsonResult(429);
        $jsonResult->method('setData')->willReturnSelf();
        $this->jsonFactory->method('create')->willReturn($jsonResult);

        $controller = $this->buildController();
        $controller->execute();
    }

    // -------------------------------------------------------------------------
    // Test 4 – Missing logout_token returns 400
    // -------------------------------------------------------------------------

    public function testMissingLogoutTokenReturns400(): void
    {
        $this->rateLimiter->method('isAllowed')->willReturn(true);
        $this->request->method('getClientIp')->willReturn('127.0.0.1');
        $this->request->method('getParam')->with('logout_token', '')->willReturn('');

        $jsonResult = $this->makeJsonResult(400);
        $jsonResult->method('setData')->willReturnSelf();
        $this->jsonFactory->method('create')->willReturn($jsonResult);

        $controller = $this->buildController();
        $controller->execute();
    }
}
