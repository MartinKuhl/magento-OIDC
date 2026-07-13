<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Cron;

use Magento\Framework\DataObject;
use Magento\Framework\Encryption\EncryptorInterface;
use M2Oidc\OAuth\Cron\HealthCheckAlert;
use M2Oidc\OAuth\Helper\Curl;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Logger\OidcLogger;
use M2Oidc\OAuth\Model\Health\ProviderReachabilityChecker;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\Collection;
use M2Oidc\OAuth\Model\ResourceModel\M2OidcOauthClientApps\CollectionFactory as ProviderCollectionFactory;
use M2Oidc\OAuth\Model\Validation\SsrfUrlValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cron\HealthCheckAlert.
 *
 * Verifies the fire-exactly-once-per-outage state machine: a webhook fires when
 * consecutive failures cross the configured threshold, stays silent on every
 * subsequent tick while still down, and fires exactly one recovery notification
 * when the provider becomes reachable again.
 *
 * @covers \M2Oidc\OAuth\Cron\HealthCheckAlert
 */
class HealthCheckAlertTest extends TestCase
{
    private const WEBHOOK_URL = 'https://hooks.example.com/alert';

    /** @var ProviderCollectionFactory&MockObject */
    private ProviderCollectionFactory $collectionFactory;

    /** @var Curl&MockObject */
    private Curl $curl;

    /** @var ProviderReachabilityChecker&MockObject */
    private ProviderReachabilityChecker $reachabilityChecker;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var OidcLogger&MockObject */
    private OidcLogger $oidcLogger;

    /** @var SsrfUrlValidator&MockObject */
    private SsrfUrlValidator $ssrfUrlValidator;

    /** @var EncryptorInterface&MockObject */
    private EncryptorInterface $encryptor;

    /** @var HealthCheckAlert */
    private HealthCheckAlert $cron;

    protected function setUp(): void
    {
        $this->collectionFactory   = $this->createMock(ProviderCollectionFactory::class);
        $this->curl                = $this->createMock(Curl::class);
        $this->reachabilityChecker = $this->createMock(ProviderReachabilityChecker::class);
        $this->oauthUtility        = $this->createMock(OAuthUtility::class);
        $this->oidcLogger          = $this->createMock(OidcLogger::class);
        $this->ssrfUrlValidator    = $this->createMock(SsrfUrlValidator::class);
        $this->encryptor           = $this->createMock(EncryptorInterface::class);

        $this->ssrfUrlValidator->method('isAllowedExternalHttpsUrl')->willReturn(true);
        $this->encryptor->method('decrypt')->willReturnArgument(0);

        $this->cron = new HealthCheckAlert(
            $this->collectionFactory,
            $this->curl,
            $this->reachabilityChecker,
            $this->oauthUtility,
            $this->oidcLogger,
            $this->ssrfUrlValidator,
            $this->encryptor
        );
    }

    /**
     * @param DataObject[] $items
     */
    private function stubCollection(array $items): void
    {
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));

        $this->collectionFactory->method('create')->willReturn($collection);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function providerRow(array $overrides = []): DataObject
    {
        return new DataObject(array_merge([
            'id' => 7,
            'app_name' => 'okta',
            'jwks_endpoint' => 'https://idp.example.com/jwks',
            'health_alert_webhook_url' => self::WEBHOOK_URL,
            'health_alert_failure_threshold' => 3,
            'health_alert_notify_on_recovery' => 1,
            'health_alert_consecutive_failures' => 0,
            'health_alert_last_status' => 'ok',
            'health_alert_first_failure_at' => '',
        ], $overrides));
    }

    public function testFiresWebhookExactlyOnceOnThresholdCrossing(): void
    {
        $this->stubCollection([$this->providerRow(['health_alert_consecutive_failures' => 2])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(false);
        $this->curl->expects($this->once())
            ->method('sendWebhookNotification')
            ->with(self::WEBHOOK_URL, $this->callback(fn ($p) => $p['event'] === 'oidc_provider_down'))
            ->willReturn(['success' => true, 'httpCode' => 200]);

        $this->oauthUtility->expects($this->once())
            ->method('saveProviderData')
            ->with(7, $this->callback(fn ($c) => $c['health_alert_last_status'] === 'down'
                && $c['health_alert_consecutive_failures'] === 3));

        $this->cron->execute();
    }

    public function testDoesNotRefireWhileAlreadyDown(): void
    {
        $this->stubCollection([$this->providerRow([
            'health_alert_consecutive_failures' => 5,
            'health_alert_last_status' => 'down',
        ])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(false);
        $this->curl->expects($this->never())->method('sendWebhookNotification');

        $this->cron->execute();
    }

    public function testFiresRecoveryNotificationExactlyOnceAfterDown(): void
    {
        $this->stubCollection([$this->providerRow([
            'health_alert_consecutive_failures' => 6,
            'health_alert_last_status' => 'down',
            'health_alert_first_failure_at' => '2026-07-13 09:00:00',
        ])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(true);
        $this->curl->expects($this->once())
            ->method('sendWebhookNotification')
            ->with(self::WEBHOOK_URL, $this->callback(fn ($p) => $p['event'] === 'oidc_provider_recovered'))
            ->willReturn(['success' => true, 'httpCode' => 200]);

        $this->oauthUtility->expects($this->once())
            ->method('saveProviderData')
            ->with(7, $this->callback(fn ($c) => $c['health_alert_last_status'] === 'ok'
                && $c['health_alert_consecutive_failures'] === 0));

        $this->cron->execute();
    }

    public function testSuppressesRecoveryNotificationWhenDisabled(): void
    {
        $this->stubCollection([$this->providerRow([
            'health_alert_consecutive_failures' => 6,
            'health_alert_last_status' => 'down',
            'health_alert_notify_on_recovery' => 0,
        ])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(true);
        $this->curl->expects($this->never())->method('sendWebhookNotification');

        $this->oauthUtility->expects($this->once())
            ->method('saveProviderData')
            ->with(7, $this->callback(fn ($c) => $c['health_alert_last_status'] === 'ok'));

        $this->cron->execute();
    }

    public function testSkipsProviderWithNothingToProbe(): void
    {
        $this->stubCollection([$this->providerRow()]);
        $this->reachabilityChecker->method('isReachable')->willReturn(null);

        $this->curl->expects($this->never())->method('sendWebhookNotification');
        $this->oauthUtility->expects($this->never())->method('saveProviderData');

        $this->cron->execute();
    }

    public function testDirtyWriteSkippedWhenSteadilyHealthy(): void
    {
        $this->stubCollection([$this->providerRow()]); // consecutive_failures=0, status=ok, no first_failure_at
        $this->reachabilityChecker->method('isReachable')->willReturn(true);

        $this->oauthUtility->expects($this->never())->method('saveProviderData');

        $this->cron->execute();
    }

    public function testRefusesToSendWebhookWhenSsrfValidationFailsAtFireTime(): void
    {
        $this->stubCollection([$this->providerRow(['health_alert_consecutive_failures' => 2])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(false);
        $this->ssrfUrlValidator = $this->createMock(SsrfUrlValidator::class);
        $this->ssrfUrlValidator->method('isAllowedExternalHttpsUrl')->willReturn(false);
        $this->cron = new HealthCheckAlert(
            $this->collectionFactory,
            $this->curl,
            $this->reachabilityChecker,
            $this->oauthUtility,
            $this->oidcLogger,
            $this->ssrfUrlValidator,
            $this->encryptor
        );

        $this->curl->expects($this->never())->method('sendWebhookNotification');

        $this->cron->execute();
    }

    public function testDecryptsWebhookUrlBeforeSending(): void
    {
        $this->stubCollection([$this->providerRow([
            'health_alert_consecutive_failures' => 2,
            'health_alert_webhook_url' => '0:2:ciphertext==',
        ])]);
        $this->reachabilityChecker->method('isReachable')->willReturn(false);

        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->encryptor->method('decrypt')->with('0:2:ciphertext==')->willReturn(self::WEBHOOK_URL);
        $this->cron = new HealthCheckAlert(
            $this->collectionFactory,
            $this->curl,
            $this->reachabilityChecker,
            $this->oauthUtility,
            $this->oidcLogger,
            $this->ssrfUrlValidator,
            $this->encryptor
        );

        $this->curl->expects($this->once())
            ->method('sendWebhookNotification')
            ->with(self::WEBHOOK_URL, $this->isType('array'))
            ->willReturn(['success' => true, 'httpCode' => 200]);

        $this->cron->execute();
    }

    public function testSkipsProvidersWithZeroThresholdAtQueryLevel(): void
    {
        // Filters are applied at the collection level; verify the cron actually
        // constrains the query rather than filtering in PHP after loading everything.
        $collection = $this->getMockBuilder(Collection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addFieldToFilter', 'getIterator'])
            ->getMock();
        $collection->expects($this->atLeastOnce())
            ->method('addFieldToFilter')
            ->with(
                $this->logicalOr(
                    'is_active',
                    'health_alert_failure_threshold',
                    'health_alert_webhook_url'
                ),
                $this->anything()
            )
            ->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->collectionFactory->method('create')->willReturn($collection);

        $this->cron->execute();
    }
}
