<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaDashboard\View;

use Magebit\Mcp\Api\Data\TokenInterface;
use Magebit\Mcp\Model\Acl\AclChecker;
use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\Auth\AuthenticatedContextProvider;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Dashboard\DashboardCatalog;
use Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\ToolPayloadTrait;
use Magebit\McpHyvaCommerceTools\Tool\HyvaDashboard\View\WidgetList;
use Magento\Framework\Exception\AuthorizationException;
use Magento\User\Model\User;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\RefusalAssertTrait;
use PHPUnit\Framework\TestCase;

class WidgetListTest extends TestCase
{
    use RefusalAssertTrait;
    use ToolPayloadTrait;

    public function testAHyvaPermissionRefusalStaysARefusal(): void
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $catalog = $this->createMock(DashboardCatalog::class);
        $catalog->method('widgets')->willThrowException(new AuthorizationException(__('Not allowed.')));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(6);
        $context = new AuthenticatedContextProvider();
        $context->set(new AuthenticatedContext($this->createMock(TokenInterface::class), $user));
        $tool = new WidgetList($features, $catalog, new CallerContext($context, $this->createMock(AclChecker::class)));

        $this->assertRefused(fn () => $tool->execute(['view_id' => 1]), 'acl');
    }
}
