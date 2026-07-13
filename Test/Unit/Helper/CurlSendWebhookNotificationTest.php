<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Helper;

use Magento\Framework\HTTP\Adapter\Curl as CurlAdapter;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Curl::sendWebhookNotification().
 *
 * Verifies the method always issues a real POST (never falls into callAPI()'s
 * empty-payload-means-GET branch), uses a fixed timeout independent of the
 * per-provider http_timeout config, never throws, and reports success only for 2xx.
 *
 * @covers \M2Oidc\OAuth\Helper\Curl::sendWebhookNotification
 */
class CurlSendWebhookNotificationTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var CurlFactory&MockObject */
    private CurlFactory $curlFactory;

    /** @var CurlAdapter&MockObject */
    private CurlAdapter $curlAdapter;

    /** @var Curl */
    private Curl $curl;

    protected function setUp(): void
    {
        $this->oauthUtility = $this->createMock(OAuthUtility::class);
        $this->curlFactory  = $this->createMock(CurlFactory::class);
        $this->curlAdapter  = $this->createMock(CurlAdapter::class);

        $this->curlFactory->method('create')->willReturn($this->curlAdapter);

        $this->curl = new Curl($this->oauthUtility, $this->curlFactory);
    }

    public function testAlwaysIssuesPostRegardlessOfPayloadShape(): void
    {
        $this->curlAdapter->expects($this->once())
            ->method('write')
            ->with('POST', 'https://hooks.example.com/alert', '1.1', $this->isType('array'), $this->isType('string'));
        $this->curlAdapter->method('getInfo')->willReturn(200);

        $result = $this->curl->sendWebhookNotification('https://hooks.example.com/alert', []);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['httpCode']);
    }

    public function testUsesFixedWebhookTimeoutNotPerProviderTimeout(): void
    {
        $this->oauthUtility->expects($this->never())->method('getStoreConfig');
        $this->curlAdapter->method('getInfo')->willReturn(200);

        $this->curl->sendWebhookNotification('https://hooks.example.com/alert', ['event' => 'oidc_provider_down']);
    }

    public function testReturnsFailureWithoutThrowingOnException(): void
    {
        $this->curlAdapter->method('write')->willThrowException(new \RuntimeException('connection refused'));

        $result = $this->curl->sendWebhookNotification('https://hooks.example.com/alert', ['event' => 'x']);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['httpCode']);
    }

    /**
     * @dataProvider nonSuccessHttpCodeProvider
     */
    public function testReturnsFailureForNonSuccessHttpCode(int $httpCode): void
    {
        $this->curlAdapter->method('getInfo')->willReturn($httpCode);

        $result = $this->curl->sendWebhookNotification('https://hooks.example.com/alert', ['event' => 'x']);

        $this->assertFalse($result['success']);
        $this->assertSame($httpCode, $result['httpCode']);
    }

    /** @return array<string, array{int}> */
    public static function nonSuccessHttpCodeProvider(): array
    {
        return [
            'redirect not followed to success' => [301],
            'client error'                     => [404],
            'server error'                     => [500],
        ];
    }

    public function testReturnsSuccessForVariousSuccessHttpCodes(): void
    {
        $this->curlAdapter->method('getInfo')->willReturn(204);

        $result = $this->curl->sendWebhookNotification('https://hooks.example.com/alert', ['event' => 'x']);

        $this->assertTrue($result['success']);
    }
}
