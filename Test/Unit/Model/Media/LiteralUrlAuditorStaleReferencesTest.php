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
 * T-159: after a trash restore, the image fields that still hold the old asset id of a restored path.
 *
 * Hyvä's trash row keeps the path relative to the media directory ("wysiwyg/a.jpg"); content stores it in
 * imageOptions.path or, without one, in src (a media URL), both normalised to that form.
 */
class LiteralUrlAuditorStaleReferencesTest extends TestCase
{
    use ContentScopeStub;

    /**
     * Asset 43 is the restored wysiwyg/a.jpg; ids 26, 27 and 30 no longer resolve.
     *
     * @return void
     */
    public function testOnlyStaleIdsOfTheRestoredPathsAreListedWithTheirOldId(): void
    {
        $result = $this->auditor()->staleReferences(['wysiwyg/a.jpg', 'wysiwyg/a b.jpg']);

        $this->assertSame([
            'wysiwyg/a.jpg' => [$this->reference('u1', 'wysiwyg/a.jpg', 26)],
            'wysiwyg/a b.jpg' => [$this->reference('u2', 'wysiwyg/a b.jpg', 27)],
        ], $result);
    }

    /**
     * A path that no stale field holds gets no key.
     *
     * @return void
     */
    public function testAPathWithoutStaleReferencesIsLeftOut(): void
    {
        $this->assertSame([], $this->auditor()->staleReferences(['wysiwyg/unused.jpg']));
    }

    /**
     * Literal, id-backed and rich text references to the same path, and the stale id of another path, are ignored.
     *
     * @return void
     */
    public function testNonStaleFindingsAndOtherPathsAreIgnored(): void
    {
        $result = $this->auditor()->staleReferences(['wysiwyg/a.jpg']);

        $this->assertSame(['wysiwyg/a.jpg' => [$this->reference('u1', 'wysiwyg/a.jpg', 26)]], $result);
    }

    /**
     * Without paths there is nothing to find: the content is not scanned.
     *
     * @return void
     */
    public function testNoPathsDoesNotScanTheContent(): void
    {
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->expects($this->never())->method('getDocuments');

        $this->assertSame([], $this->auditor($scanner)->staleReferences([]));
    }

    /**
     * Expected reference: the finding without its category.
     *
     * @param string $uid
     * @param string $path
     * @param int $assetId
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function reference(string $uid, string $path, int $assetId): array
    {
        return ['entity_type' => 'cms_page', 'id' => 13, 'identifier' => 'sale', 'uid' => $uid,
            'component' => 'image', 'field' => 'image', 'path' => $path, 'asset_id' => $assetId];
    }

    /**
     * Auditor over one page with every kind of image field on and around the restored paths.
     *
     * @param ContentScanner|null $scanner Replaces the scanner that returns the page.
     * @return LiteralUrlAuditor
     */
    private function auditor(?ContentScanner $scanner = null): LiteralUrlAuditor
    {
        $image = static fn (string $uid, array $value): array => ['uid' => $uid, 'component' => 'image',
            'image' => $value];
        $nodes = [
            // Stale id, path in imageOptions.path; src carries a cache-busting query.
            $image('u1', ['src' => '/media/wysiwyg/a.jpg?v=1', 'imageOptions' => ['id' => 26,
                'path' => 'wysiwyg/a.jpg']]),
            // Stale id, no imageOptions.path: the path comes from the encoded media URL in src.
            $image('u2', ['src' => 'https://store.test/media/wysiwyg/a%20b.jpg', 'imageOptions' => ['id' => 27]]),
            // Same path, id that resolves: id_backed.
            $image('u3', ['src' => 'wysiwyg/a.jpg', 'imageOptions' => ['id' => 43, 'path' => 'wysiwyg/a.jpg']]),
            // Same path, no id but a gallery asset: literal_fixable.
            $image('u4', ['src' => 'wysiwyg/a.jpg']),
            // Same path in rich text: richtext_literal.
            ['uid' => 'u5', 'component' => 'text', 'content' => '<p><img src="/media/wysiwyg/a.jpg" alt=""></p>'],
            // Stale id of another path.
            $image('u6', ['src' => 'wysiwyg/other.jpg', 'imageOptions' => ['id' => 30,
                'path' => 'wysiwyg/other.jpg']]),
        ];
        if ($scanner === null) {
            $scanner = $this->createMock(ContentScanner::class);
            $scanner->method('getDocuments')->willReturn([['entity_type' => 'cms_page', 'id' => 13,
                'identifier' => 'sale', 'title' => 'Sale', 'edition' => 'draft', 'error' => null,
                'document' => ['nodes' => $nodes]]]);
        }
        $tree = $this->createMock(ComponentTree::class);
        $tree->method('walk')->willReturnCallback(static function (array $document, callable $visit): void {
            foreach ($document['nodes'] as $node) {
                $visit($node);
            }
        });
        $resolver = $this->createMock(ImageAssetPathResolverInterface::class);
        $resolver->method('resolvePathById')
            ->willReturnCallback(static fn (?int $id): ?string => $id === 43 ? 'wysiwyg/a.jpg' : null);
        $resolver->method('resolveIdByPath')
            ->willReturnCallback(static fn (?string $path): ?int => $path === 'wysiwyg/a.jpg' ? 43 : null);
        return new LiteralUrlAuditor(
            $scanner,
            $tree,
            new ImageReferenceExtractor($tree, $resolver),
            $resolver,
            $this->contentScope(null)
        );
    }
}
