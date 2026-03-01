<?php

declare(strict_types=1);

namespace MiniOrange\OAuth\Test\Unit\Controller;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use MiniOrange\OAuth\Controller\Adminhtml\Providersettings\Index;
use MiniOrange\OAuth\Helper\OAuthUtility;
use MiniOrange\OAuth\Model\MiniorangeOauthClientApps;
use MiniOrange\OAuth\Model\MiniorangeOauthClientAppsFactory;
use MiniOrange\OAuth\Model\ResourceModel\MiniOrangeOauthClientApps as AppResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for Providersettings/Index controller (MP-06).
 *
 * Tests the execute() save path:
 *  - GET request renders page without saving
 *  - POST with valid provider_id saves fields and adds success message
 *  - POST with missing/zero provider_id adds error message, skips save
 *  - POST with unknown provider_id (model->getId() === null) adds error message
 *  - login_type is sanitised to 'customer' when invalid value is submitted
 *  - button_color is cleared when not a valid hex colour
 *
 * @covers \MiniOrange\OAuth\Controller\Adminhtml\Providersettings\Index
 */
class ProviderSettingsIndexTest extends TestCase
{
    /** @var Context&MockObject */
    private Context $context;

    /** @var HttpRequest&MockObject */
    private HttpRequest $request;

    /** @var PageFactory&MockObject */
    private PageFactory $pageFactory;

    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var ManagerInterface&MockObject */
    private ManagerInterface $messageManager;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var MiniorangeOauthClientAppsFactory&MockObject */
    private MiniorangeOauthClientAppsFactory $clientAppsFactory;

    /** @var AppResource&MockObject */
    private AppResource $appResource;

    /** @var MiniorangeOauthClientApps&MockObject */
    private MiniorangeOauthClientApps $model;

    /** @var Index */
    private Index $controller;

    protected function setUp(): void
    {
        $this->request        = $this->createMock(HttpRequest::class);
        $this->pageFactory    = $this->createMock(PageFactory::class);
        $this->oauthUtility   = $this->createMock(OAuthUtility::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger         = $this->createMock(LoggerInterface::class);

        $this->clientAppsFactory = $this->createMock(MiniorangeOauthClientAppsFactory::class);
        $this->appResource       = $this->createMock(AppResource::class);
        $this->model             = $this->createMock(MiniorangeOauthClientApps::class);

        // Wire Context to return our request mock
        $this->context = $this->createMock(Context::class);
        $this->context->method('getRequest')->willReturn($this->request);

        // PageFactory always returns a page whose config chain is a no-op mock
        $page   = $this->createMock(Page::class);
        $config = $this->createMock(\Magento\Framework\View\Page\Config::class);
        $title  = $this->createMock(\Magento\Framework\View\Page\Title::class);
        $config->method('getTitle')->willReturn($title);
        $page->method('getConfig')->willReturn($config);
        $this->pageFactory->method('create')->willReturn($page);

        $this->controller = new Index(
            $this->context,
            $this->pageFactory,
            $this->oauthUtility,
            $this->messageManager,
            $this->logger,
            $this->clientAppsFactory,
            $this->appResource
        );
    }

    // -------------------------------------------------------------------------
    // GET — no form submission
    // -------------------------------------------------------------------------

    public function testGetRequestRendersPageWithoutSaving(): void
    {
        // No 'option' key → isFormOptionBeingSaved() returns false
        $this->request->method('getParams')->willReturn([]);

        $this->clientAppsFactory->expects($this->never())->method('create');
        $this->appResource->expects($this->never())->method('save');
        $this->messageManager->expects($this->never())->method('addSuccessMessage');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // POST — valid provider, full save flow
    // -------------------------------------------------------------------------

    public function testSaveWithValidProviderIdPersistsAllFields(): void
    {
        $this->request->method('getParams')->willReturn([
            'option'       => 'saveProviderSettings',
            'provider_id'  => '7',
            'display_name' => '  My Provider  ',
            'login_type'   => 'admin',
            'is_active'    => '1',
            'sort_order'   => '3',
            'button_label' => 'Login via SSO',
            'button_color' => '#1a2b3c',
        ]);

        $this->clientAppsFactory->method('create')->willReturn($this->model);
        $this->model->method('getId')->willReturn(7);

        // Verify each field is set correctly
        $this->model->expects($this->exactly(6))
            ->method('setData')
            ->willReturnCallback(function (string $key, mixed $value) {
                match ($key) {
                    'display_name' => $this->assertSame('My Provider', $value),
                    'login_type'   => $this->assertSame('admin', $value),
                    'is_active'    => $this->assertSame(1, $value),
                    'sort_order'   => $this->assertSame(3, $value),
                    'button_label' => $this->assertSame('Login via SSO', $value),
                    'button_color' => $this->assertSame('#1a2b3c', $value),
                    default        => $this->fail("Unexpected setData() key: $key"),
                };
                return $this->model;
            });

        $this->appResource->expects($this->once())->method('save')->with($this->model);
        $this->oauthUtility->expects($this->once())->method('flushCache');
        $this->oauthUtility->expects($this->once())->method('reinitConfig');
        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // POST — missing / zero provider_id
    // -------------------------------------------------------------------------

    public function testSaveWithMissingProviderIdAddsErrorAndSkipsSave(): void
    {
        $this->request->method('getParams')->willReturn([
            'option'      => 'saveProviderSettings',
            'provider_id' => '0',
        ]);

        $this->clientAppsFactory->expects($this->never())->method('create');
        $this->appResource->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // POST — provider not found in DB (model->getId() returns null)
    // -------------------------------------------------------------------------

    public function testSaveWithUnknownProviderIdAddsErrorAndSkipsSave(): void
    {
        $this->request->method('getParams')->willReturn([
            'option'      => 'saveProviderSettings',
            'provider_id' => '99',
        ]);

        $this->clientAppsFactory->method('create')->willReturn($this->model);
        $this->model->method('getId')->willReturn(null);

        $this->appResource->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->controller->execute();
    }

    // -------------------------------------------------------------------------
    // Input sanitisation — login_type
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidLoginTypeProvider
     */
    public function testInvalidLoginTypeFallsBackToCustomer(string $badValue): void
    {
        $this->request->method('getParams')->willReturn([
            'option'      => 'saveProviderSettings',
            'provider_id' => '1',
            'login_type'  => $badValue,
        ]);

        $this->clientAppsFactory->method('create')->willReturn($this->model);
        $this->model->method('getId')->willReturn(1);

        $savedLoginType = null;
        $this->model->method('setData')
            ->willReturnCallback(function (string $key, mixed $value) use (&$savedLoginType) {
                if ($key === 'login_type') {
                    $savedLoginType = $value;
                }
                return $this->model;
            });

        $this->controller->execute();

        $this->assertSame('customer', $savedLoginType, "Invalid login_type '$badValue' must fall back to 'customer'");
    }

    /** @return array<string, array{string}> */
    public static function invalidLoginTypeProvider(): array
    {
        return [
            'superadmin'  => ['superadmin'],
            'empty string' => [''],
            'sql injection' => ["' OR 1=1--"],
            'uppercase'    => ['ADMIN'],
        ];
    }

    // -------------------------------------------------------------------------
    // Input sanitisation — button_color
    // -------------------------------------------------------------------------

    /**
     * @dataProvider invalidColorProvider
     */
    public function testInvalidButtonColorIsClearedToEmptyString(string $badColor): void
    {
        $this->request->method('getParams')->willReturn([
            'option'       => 'saveProviderSettings',
            'provider_id'  => '1',
            'button_color' => $badColor,
        ]);

        $this->clientAppsFactory->method('create')->willReturn($this->model);
        $this->model->method('getId')->willReturn(1);

        $savedColor = 'NOTSET';
        $this->model->method('setData')
            ->willReturnCallback(function (string $key, mixed $value) use (&$savedColor) {
                if ($key === 'button_color') {
                    $savedColor = $value;
                }
                return $this->model;
            });

        $this->controller->execute();

        $this->assertSame('', $savedColor, "Invalid color '$badColor' must be stored as empty string");
    }

    /** @return array<string, array{string}> */
    public static function invalidColorProvider(): array
    {
        return [
            'no hash'          => ['eb5202'],
            'too short'        => ['#eb520'],
            'too long'         => ['#eb52020'],
            'XSS attempt'      => ['<script>alert(1)</script>'],
            'javascript scheme' => ['javascript:alert(1)'],
            'empty'            => [''],
        ];
    }

    /**
     * @dataProvider validColorProvider
     */
    public function testValidButtonColorIsStoredAsIs(string $goodColor): void
    {
        $this->request->method('getParams')->willReturn([
            'option'       => 'saveProviderSettings',
            'provider_id'  => '1',
            'button_color' => $goodColor,
        ]);

        $this->clientAppsFactory->method('create')->willReturn($this->model);
        $this->model->method('getId')->willReturn(1);

        $savedColor = null;
        $this->model->method('setData')
            ->willReturnCallback(function (string $key, mixed $value) use (&$savedColor) {
                if ($key === 'button_color') {
                    $savedColor = $value;
                }
                return $this->model;
            });

        $this->controller->execute();

        $this->assertSame($goodColor, $savedColor, "Valid color '$goodColor' must be stored unchanged");
    }

    /** @return array<string, array{string}> */
    public static function validColorProvider(): array
    {
        return [
            'lowercase hex' => ['#eb5202'],
            'uppercase hex' => ['#EB5202'],
            'mixed case'    => ['#1A2b3C'],
            'black'         => ['#000000'],
            'white'         => ['#ffffff'],
        ];
    }
}
