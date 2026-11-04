<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\FieldValueFormat;
use PHPUnit\Framework\TestCase;

/**
 * T-153: the link format follows Hyvä CMS 1.3.2+ Block\Element::getLinkData() (label, then placeholder, then URL;
 * aria_label).
 */
class FieldValueFormatTest extends TestCase
{
    public function testTheLinkFormatHasTheAriaLabelKey(): void
    {
        $link = (new FieldValueFormat())->get('link');

        $this->assertNotNull($link);
        $this->assertStringContainsString('aria_label?', $link['format']);
        $example = $link['example'] ?? null;
        $this->assertIsArray($example);
        $this->assertArrayHasKey('aria_label', $example);
    }

    public function testTheLinkNotesDescribeTheEmptyLabelFallback(): void
    {
        $notes = (new FieldValueFormat())->get('link')['notes'] ?? '';

        $this->assertStringContainsString('missing or empty, Hyvä CMS 1.3.2+ shows placeholder', $notes);
        $this->assertStringContainsString('the link text is the URL', $notes);
        $this->assertStringContainsString('config.aria_label: true', $notes);
        $this->assertStringNotContainsString('an empty "" label shows the URL.', $notes);
    }
}
