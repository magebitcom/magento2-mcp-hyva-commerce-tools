<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Auth;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;

class CallerContextTest extends TestCase
{
    public function testOutsideAnMcpCallEverythingIsUnknown(): void
    {
        $acl = $this->createMock(AclChecker::class);
        $acl->expects($this->never())->method('isAllowed');
        $caller = new CallerContext(new AuthenticatedContextProvider(), $acl);

        $this->assertNull($caller->adminUserId());
        $this->assertNull($caller->adminUsername());
        $this->assertNull($caller->aclRole());
        $this->assertNull($caller->isAllowed('Hyva_Cms::cms'));
    }

    public function testInsideAnMcpCallItAnswersForTheCaller(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $user->method('getUserName')->willReturn('mcp.viewer');
        $user->method('getAclRole')->willReturn('12');
        $provider = new AuthenticatedContextProvider();
        $provider->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $acl = $this->createMock(AclChecker::class);
        $acl->method('isAllowed')->willReturnCallback(
            static fn (User $who, string $resource): bool => $who === $user && $resource === 'Hyva_Cms::page_view'
        );
        $caller = new CallerContext($provider, $acl);

        $this->assertSame(6, $caller->adminUserId());
        $this->assertSame('mcp.viewer', $caller->adminUsername());
        $this->assertSame('12', $caller->aclRole());
        $this->assertTrue($caller->isAllowed('Hyva_Cms::page_view'));
        $this->assertFalse($caller->isAllowed('Hyva_Cms::page_save'));
    }

    public function testTheCallScopePluginOnlyWrapsThisModulesTools(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../../etc/frontend/di.xml');
        $this->assertNotFalse($xml);
        $targets = [];
        foreach ($xml->xpath('//type[plugin/@name="magebit_mcp_hyva_commerce_call_scope"]') ?: [] as $type) {
            $targets[] = (string) $type['name'];
        }
        $this->assertSame(['Magebit\\McpHyvaCommerceTools\\Model\\Tool\\AbstractTool'], $targets);
    }
}
