<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Feature;

use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Feature\FeatureChecker;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\Mcp\Api\Data\TokenInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FeatureCheckerTest extends TestCase
{
    private const FEATURES = [
        'menu_builder' => [
            'label' => 'Hyvä Menu Builder',
            'package' => 'vendor/not-installed-package',
            'acl' => 'Magento_Backend::content',
            'modules' => ['Hyva_MenuBuilder'],
        ],
    ];

    /**
     * @var ComponentRegistrarInterface&MockObject
     */
    private MockObject $registrar;
    /**
     * @var ModuleManager&MockObject
     */
    private MockObject $moduleManager;
    /**
     * @var AuthenticatedContextProvider&MockObject
     */
    private MockObject $contextProvider;
    /**
     * @var AclChecker&MockObject
     */
    private MockObject $aclChecker;
    /**
     * @var AuthorizationInterface&MockObject
     */
    private MockObject $authorization;

    protected function setUp(): void
    {
        $this->registrar = $this->createMock(ComponentRegistrarInterface::class);
        $this->moduleManager = $this->createMock(ModuleManager::class);
        $this->contextProvider = $this->createMock(AuthenticatedContextProvider::class);
        $this->aclChecker = $this->createMock(AclChecker::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
    }

    public function testAvailableWhenInstalledEnabledAndAllowed(): void
    {
        $this->registrar->method('getPath')->willReturn('/path');
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->withMcpContext(true);

        $status = $this->checker()->getStatus('menu_builder');
        $this->assertTrue($status['installed']);
        $this->assertTrue($status['enabled']);
        $this->assertTrue($status['acl_allowed']);
        $this->assertTrue($status['available']);
        $this->assertNull($status['version']);
    }

    public function testNotInstalled(): void
    {
        $this->registrar->method('getPath')->willReturn(null);
        $this->withMcpContext(true);
        $checker = $this->checker();

        $this->assertFalse($checker->isAvailable('menu_builder'));
        $response = $checker->getUnavailableResponse('menu_builder');
        $this->assertFalse($response['available']);
        $this->assertSame('menu_builder', $response['feature']);
        $this->assertStringContainsString('not installed', $response['reason']);
    }

    public function testInstalledButDisabledNamesTheModule(): void
    {
        $this->registrar->method('getPath')->willReturn('/path');
        $this->moduleManager->method('isEnabled')->willReturn(false);
        $this->withMcpContext(true);

        $response = $this->checker()->getUnavailableResponse('menu_builder');
        $this->assertStringContainsString('disabled (Hyva_MenuBuilder)', $response['reason']);
        $this->assertStringContainsString('module:enable Hyva_MenuBuilder', $response['hint']);
    }

    public function testAclDenied(): void
    {
        $this->registrar->method('getPath')->willReturn('/path');
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->withMcpContext(false);
        $checker = $this->checker();

        $this->assertFalse($checker->isAvailable('menu_builder'));
        $this->assertStringContainsString(
            'Magento_Backend::content',
            $checker->getUnavailableResponse('menu_builder')['reason']
        );
    }

    public function testFallsBackToAdminSessionOutsideMcp(): void
    {
        $this->registrar->method('getPath')->willReturn('/path');
        $this->moduleManager->method('isEnabled')->willReturn(true);
        $this->contextProvider->method('get')->willThrowException(new LocalizedException(__('none')));
        $this->authorization->expects($this->once())
            ->method('isAllowed')
            ->with('Magento_Backend::content')
            ->willReturn(true);

        $this->assertTrue($this->checker()->getStatus('menu_builder')['acl_allowed']);
    }

    public function testUnknownFeatureIsUnavailable(): void
    {
        $this->withMcpContext(true);
        $status = $this->checker()->getStatus('nope');
        $this->assertFalse($status['installed']);
        $this->assertFalse($status['available']);
        $this->assertSame(['menu_builder'], array_keys($this->checker()->getAll()));
    }

    private function withMcpContext(bool $allowed): void
    {
        $context = new AuthenticatedContext($this->createMock(TokenInterface::class), $this->createMock(User::class));
        $this->contextProvider->method('get')->willReturn($context);
        $this->aclChecker->method('isAllowed')->willReturn($allowed);
    }

    private function checker(): FeatureChecker
    {
        return new FeatureChecker(
            $this->registrar,
            $this->moduleManager,
            new CallerContext($this->contextProvider, $this->aclChecker),
            $this->authorization,
            $this->createMock(LoggerInterface::class),
            self::FEATURES
        );
    }
}
