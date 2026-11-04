<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCancel;
use PHPUnit\Framework\TestCase;

class ScheduleCancelSchemaTest extends TestCase
{
    public function testItemIdsAreCapped(): void
    {
        $tool = new ScheduleCancel(
            $this->createMock(ScheduleService::class),
            $this->createMock(ContentAcl::class),
            $this->createMock(ContentLocator::class),
            $this->viewableAll()
        );
        $schema = $tool->getInputSchema();
        $properties = $schema['properties'] ?? null;

        $this->assertIsArray($properties);
        $this->assertIsArray($properties['item_ids'] ?? null);
        $this->assertSame(100, $properties['item_ids']['maxItems'] ?? null);
    }

    /**
     * Scope filter for a caller who may view every content type.
     *
     * @return ContentScopeFilter
     */
    private function viewableAll(): ContentScopeFilter
    {
        $filter = $this->createMock(ContentScopeFilter::class);
        $filter->method('viewableTypes')->willReturnArgument(0);
        return $filter;
    }
}
