<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Media;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Media\LiteralUrlAuditor;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content\ContentScopeStub;
use PHPUnit\Framework\TestCase;

/**
 * hyva_media.audit.literal_urls and the overview lens only scan documents a restricted caller may see (finding:
 * unscoped image/audit lists).
 */
class LiteralUrlAuditorScopeTest extends TestCase
{
    use ContentScopeStub;

    public function testARestrictedCallerScansOnlyItsDocuments(): void
    {
        $report = $this->auditor([1])->audit();

        $this->assertSame(1, $report['documents_checked']);
        $this->assertSame([1], array_column($report['findings'], 'id'));
        $this->assertSame(1, $report['counts']['literal_not_in_gallery']);
        $this->assertSame(1, $report['documents_hidden_by_permissions'] ?? null);
    }

    public function testAnUnrestrictedCallerScansEveryDocument(): void
    {
        $report = $this->auditor(null)->audit();

        $this->assertSame(2, $report['documents_checked']);
        $this->assertSame([1, 2], array_column($report['findings'], 'id'));
        $this->assertArrayNotHasKey('documents_hidden_by_permissions', $report);
    }

    /**
     * Auditor over one literal image in page 1 (store view 1) and one in page 2 (store view 2).
     *
     * @param array|null $allowedStoreIds
     * @return LiteralUrlAuditor
     * @phpstan-param list<int>|null $allowedStoreIds
     */
    private function auditor(?array $allowedStoreIds): LiteralUrlAuditor
    {
        $documents = [];
        foreach ([1, 2] as $id) {
            $documents[] = ['entity_type' => 'cms_page', 'id' => $id, 'identifier' => 'p' . $id, 'title' => '',
                'edition' => 'draft', 'error' => null,
                'document' => ['nodes' => [['uid' => 'u' . $id, 'component' => 'image',
                    'image' => ['src' => 'wysiwyg/p' . $id . '.jpg']]]]];
        }
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('getDocuments')->willReturn($documents);
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('walk')->willReturnCallback(static function (array $document, callable $visit): void {
            foreach ($document['nodes'] as $node) {
                $visit($node);
            }
        });
        $resolver = $this->createMock(ImageAssetPathResolverInterface::class);
        $resolver->method('resolveIdByPath')->willReturn(null);
        return new LiteralUrlAuditor(
            $scanner,
            $tree,
            new ImageReferenceExtractor($tree, $resolver),
            $resolver,
            $this->contentScope($allowedStoreIds, ['cms_page' => [1 => [1], 2 => [2]]])
        );
    }
}
