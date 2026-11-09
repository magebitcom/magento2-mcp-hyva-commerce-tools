<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\ViewModel\EntityPickerValue;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityPickerReference;
use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * T-183: a Hyvä cms_block component names its block through an entity picker. The Liveview editor saves a
 * {source, id, identifier, label} object (id = block id) or a list of them; older content holds the identifier or the
 * block id as a string. Every shape counts as a usage of the block. Form Builder forms name their success content block
 * with the same picker (success_content_block on the form root), listed with the form as the entity.
 */
class UsageFinderBlockTest extends TestCase
{
    use OptionalServicesStub;

    public function testEveryShapeOfTheBlockPickerNamingTheBlockIsFound(): void
    {
        $nodes = [
            ['id' => 1, 'node' => ['component' => 'cms_block', 'block_identifier' => 'promo']],
            ['id' => 2, 'node' => ['component' => 'cms_block', 'block_identifier' => '42']],
            ['id' => 3, 'node' => ['component' => 'cms_block', 'block_identifier' => [
                'source' => 'cms_block', 'id' => 42, 'identifier' => 'promo', 'label' => 'Promo',
            ]]],
            ['id' => 4, 'node' => ['component' => 'cms_block', 'block_identifier' => [
                'source' => 'cms_block', 'id' => 42, 'label' => 'Promo',
            ]]],
            ['id' => 5, 'node' => ['component' => 'cms_block', 'block_identifier' => [
                ['source' => 'cms_block', 'id' => 7, 'identifier' => 'footer', 'label' => 'Footer'],
                ['source' => 'cms_block', 'id' => 42, 'identifier' => 'promo', 'label' => 'Promo'],
            ]]],
            ['id' => 6, 'node' => ['component' => 'cms_block', 'block_identifier' => [
                'source' => 'cms_block', 'id' => 7, 'identifier' => 'footer', 'label' => 'Footer',
            ]]],
            ['id' => 7, 'node' => ['component' => 'cms_block', 'block_identifier' => [
                'source' => 'cms_page', 'id' => 42, 'identifier' => 'promo', 'label' => 'Promo page',
            ]]],
            ['id' => 8, 'node' => ['component' => 'cms_block', 'block_identifier' => 'footer']],
            ['id' => 9, 'node' => ['component' => 'widget', 'widget' => [
                'directive' => '{{widget type="Magento\\Cms\\Block\\Widget\\Block" block_id="promo"}}',
            ]]],
        ];
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->expects($this->once())->method('findNodes')->willReturnCallback(
            static function (callable $predicate, array $types, bool $ignoreMenuAcl) use ($nodes): array {
                $matches = [];
                foreach ($nodes as $entry) {
                    if ($predicate($entry['node'])) {
                        $matches[] = ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => $entry['id']];
                    }
                }
                return $matches;
            }
        );
        $scanner->method('findInMagentoContent')->willReturn([]);

        $report = (new UsageFinder($scanner, $this->resource(), new EntityPickerReference(
            $this->optionalServices([EntityPickerValue::class => new EntityPickerValue()])
        )))->findBlockUsage(42, 'promo', true);

        $this->assertSame([1, 2, 3, 4, 5, 9], array_column($report['hyva_content'], 'id'));
        $this->assertSame(6, $report['total']);
    }

    public function testAFormWhoseSuccessContentBlockIsTheBlockIsAUsage(): void
    {
        $forms = [
            ['id' => 11, 'node' => ['component' => 'hyva_form', 'success_content_block' => [
                'source' => 'cms_block', 'id' => 42, 'identifier' => 'promo', 'label' => 'Promo',
            ]]],
            ['id' => 12, 'node' => ['component' => 'hyva_form', 'success_content_block' => [
                ['source' => 'cms_block', 'id' => 7, 'identifier' => 'footer', 'label' => 'Footer'],
                ['source' => 'cms_block', 'id' => 42, 'label' => 'Promo'],
            ]]],
            ['id' => 13, 'node' => ['component' => 'hyva_form', 'success_content_block' => 'promo']],
            ['id' => 14, 'node' => ['component' => 'hyva_form', 'success_content_block' => [
                'source' => 'cms_block', 'id' => 7, 'identifier' => 'footer', 'label' => 'Footer',
            ]]],
            ['id' => 15, 'node' => ['component' => 'hyva_form', 'success_content_block' => [
                'source' => 'cms_page', 'id' => 42, 'identifier' => 'promo', 'label' => 'Promo page',
            ]]],
            ['id' => 16, 'node' => ['component' => 'hyva_form', 'success_content_block' => null]],
            ['id' => 17, 'node' => ['component' => 'hyva_form', 'success_redirect_url' => [
                'type' => 'cms_page', 'value' => '42',
            ]]],
        ];
        $page = ['entity_type' => ContentLocator::TYPE_PAGE, 'id' => 1];
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->method('findNodes')->willReturn([$page]);
        $scanner->expects($this->once())->method('findFormNodes')->willReturnCallback(
            static function (callable $predicate) use ($forms): array {
                $matches = [];
                foreach ($forms as $entry) {
                    if ($predicate($entry['node'])) {
                        $matches[] = ['entity_type' => ContentScanner::TYPE_FORM, 'id' => $entry['id'],
                            'store_ids' => [1]];
                    }
                }
                return $matches;
            }
        );
        $scanner->method('findInMagentoContent')->willReturn([]);

        $report = (new UsageFinder($scanner, $this->resource(), new EntityPickerReference(
            $this->optionalServices([EntityPickerValue::class => new EntityPickerValue()])
        )))->findBlockUsage(42, 'promo', true);

        $this->assertSame(
            [[ContentLocator::TYPE_PAGE, 1], ['form', 11], ['form', 12], ['form', 13]],
            array_map(static fn (array $u): array => [$u['entity_type'], $u['id']], $report['hyva_content'])
        );
        $this->assertSame(4, $report['total']);
    }

    /**
     * Resource connection with no widget instances.
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
