<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia\Audit;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaAuditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCommerce\Audit\AuditMedia;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditAll;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditCms;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Audit\AuditMenu;
use PHPUnit\Framework\TestCase;

/**
 * The media audit tools reply with counts as a JSON object when no image has a problem (T-115).
 */
class AuditEmptyCountsTest extends TestCase
{
    /**
     * @return array<string, array{class-string<AbstractTool>}>
     */
    public static function tools(): array
    {
        return [
            'hyva_media_audit_all' => [AuditAll::class],
            'hyva_media_audit_cms' => [AuditCms::class],
            'hyva_media_audit_menu' => [AuditMenu::class],
            'hyva_commerce_audit_media' => [AuditMedia::class],
        ];
    }

    /**
     * @dataProvider tools
     * @param string $class
     * @phpstan-param class-string<AbstractTool> $class
     */
    public function testEmptyCountsEncodeAsObject(string $class): void
    {
        $auditor = $this->createMock(MediaAuditor::class);
        $auditor->method('audit')->willReturn(['scope' => 'all', 'images_checked' => 0,
            'counts' => new \stdClass(), 'config_issues' => [], 'images' => []]);

        $tool = is_subclass_of($class, AbstractMediaTool::class)
            ? new $class($this->createMock(FeatureCheckerInterface::class), $auditor)
            : new $class($auditor);
        $result = $tool->execute([]);

        $this->assertFalse($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $this->assertStringContainsString('"counts": {}', $text);
        $this->assertSame(['images_checked' => 0], $result->getAuditSummary());
    }
}
