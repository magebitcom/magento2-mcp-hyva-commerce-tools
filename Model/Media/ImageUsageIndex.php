<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;

/**
 * Where every media image is referenced: Hyvä CMS pages/blocks/menus (draft + published), Magento CMS content,
 * product and category descriptions. Built once per request.
 *
 * @phpstan-type ImageUsage array{source: string, entity_type: string, id: int, identifier: string, edition: string,
 *     uid: string|null, component: string|null, field: string|null, alt: string|null, has_alt: bool}
 */
class ImageUsageIndex
{
    public const SOURCE_HYVA_CMS = 'hyva_cms';
    public const SOURCE_MENU = 'menu';
    public const SOURCE_MAGENTO_CMS = 'magento_cms';
    public const SOURCE_PRODUCT = 'product';
    public const SOURCE_CATEGORY = 'category';
    public const SOURCES = [
        self::SOURCE_HYVA_CMS,
        self::SOURCE_MENU,
        self::SOURCE_MAGENTO_CMS,
        self::SOURCE_PRODUCT,
        self::SOURCE_CATEGORY,
    ];

    /**
     * Paths per catalog query: more paths are split into several LIKE-filtered queries, never an unfiltered scan.
     */
    private const LIKE_PATHS_PER_QUERY = 25;

    /**
     * @var array|null
     * @phpstan-var array<string, list<ImageUsage>>|null
     */
    private ?array $index = null;

    /**
     * @var array
     * @phpstan-var array<string, array<int|string, mixed>>
     */
    private array $attributeIds = [];

    /**
     * @param ContentScanner $contentScanner
     * @param ContentIndex $contentIndex
     * @param ImageReferenceExtractor $extractor
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ContentScanner $contentScanner,
        private readonly ContentIndex $contentIndex,
        private readonly ImageReferenceExtractor $extractor,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Every referenced image in all sources (used by audits; scans catalog descriptions once).
     *
     * @return array
     * @phpstan-return array<string, list<ImageUsage>>
     */
    public function all(): array
    {
        $index = $this->contentIndex();
        $this->addCatalog($index, null);
        return $index;
    }

    /**
     * Usages of one image. Catalog descriptions are queried only when requested, filtered by the path.
     *
     * @param string $path
     * @param array $sources Empty = all.
     * @return array
     * @phpstan-param list<string> $sources
     * @phpstan-return list<ImageUsage>
     */
    public function usagesOf(string $path, array $sources = []): array
    {
        return $this->usagesOfMany([$path], $sources)[$path] ?? [];
    }

    /**
     * Usages of several images, keyed by path, with one catalog pass for all of them.
     *
     * @param array $paths
     * @param array $sources Empty = all.
     * @return array
     * @phpstan-param list<string> $paths
     * @phpstan-param list<string> $sources
     * @phpstan-return array<string, list<ImageUsage>>
     */
    public function usagesOfMany(array $paths, array $sources = []): array
    {
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            return [];
        }
        $content = $this->contentIndex();
        $index = [];
        foreach ($paths as $path) {
            $index[$path] = $content[$path] ?? [];
        }
        if ($sources === [] || array_intersect($sources, [self::SOURCE_PRODUCT, self::SOURCE_CATEGORY]) !== []) {
            $this->addCatalog($index, $paths);
        }
        $result = [];
        foreach ($paths as $path) {
            $usages = $index[$path] ?? [];
            $result[$path] = $sources === [] ? $usages : array_values(
                array_filter($usages, static fn (array $u): bool => in_array($u['source'], $sources, true))
            );
        }
        return $result;
    }

    /**
     * Hyvä CMS / menu documents and Magento CMS content, built once per request.
     *
     * @return array
     * @phpstan-return array<string, list<ImageUsage>>
     */
    private function contentIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $index = [];
        $documents = $this->contentScanner->getDocuments();
        $this->extractor->prefetchAssetIds(array_column($documents, 'document'));
        foreach ($documents as $entry) {
            if ($entry['document'] === null) {
                continue;
            }
            $source = $entry['entity_type'] === ContentLocator::TYPE_MENU ? self::SOURCE_MENU : self::SOURCE_HYVA_CMS;
            foreach ($this->extractor->fromDocument($entry['document']) as $ref) {
                $index[$ref['path']][] = [
                    'source' => $source,
                    'entity_type' => $entry['entity_type'],
                    'id' => $entry['id'],
                    'identifier' => $entry['identifier'],
                    'edition' => $entry['edition'],
                    'uid' => $ref['uid'],
                    'component' => $ref['component'],
                    'field' => $ref['field'],
                    'alt' => $ref['alt'],
                    'has_alt' => $ref['has_alt'],
                ];
            }
        }
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK] as $type) {
            foreach ($this->contentIndex->getRows($type, true) as $row) {
                $this->addHtml(
                    $index,
                    self::SOURCE_MAGENTO_CMS,
                    $type,
                    $row['id'],
                    $row['identifier'],
                    $row['magento_content'] ?? '',
                    null
                );
            }
        }
        return $this->index = $index;
    }

    /**
     * Add product and category description usages, optionally only those mentioning the given paths.
     *
     * @param array $index
     * @param array|null $paths Null = every referenced image.
     * @return void
     * @phpstan-param array<string, list<ImageUsage>> $index
     * @phpstan-param list<string>|null $paths
     */
    private function addCatalog(array &$index, ?array $paths): void
    {
        $chunks = $paths === null ? [null] : array_chunk($paths, self::LIKE_PATHS_PER_QUERY);
        $sources = [
            [
                self::SOURCE_PRODUCT,
                'product',
                'catalog_product_entity_text',
                'catalog_product',
                ['description', 'short_description'],
            ],
            [self::SOURCE_CATEGORY, 'category', 'catalog_category_entity_text', 'catalog_category', ['description']],
        ];
        foreach ($sources as [$source, $type, $table, $entityTypeCode, $attributes]) {
            // Each chunk keeps only its own paths, so a row matched by two chunks adds each usage once.
            foreach ($chunks as $chunk) {
                $onlyPaths = $chunk === null ? null : array_fill_keys($chunk, true);
                foreach ($this->descriptions($table, $entityTypeCode, $attributes, $chunk ?? []) as $row) {
                    $this->addHtml($index, $source, $type, $row['id'], $row['code'], $row['value'], $onlyPaths);
                }
            }
        }
    }

    /**
     * Add html.
     *
     * @param array $index
     * @param string $source
     * @param string $type
     * @param int $id
     * @param string $identifier
     * @param string $html
     * @param array|null $onlyPaths Keep only references to these paths (keys); null = all.
     * @return void
     * @phpstan-param array<string, list<ImageUsage>> $index
     * @phpstan-param array<string, true>|null $onlyPaths
     */
    private function addHtml(
        array &$index,
        string $source,
        string $type,
        int $id,
        string $identifier,
        string $html,
        ?array $onlyPaths
    ): void {
        foreach ($this->extractor->fromHtml($html) as $ref) {
            if ($onlyPaths !== null && !isset($onlyPaths[$ref['path']])) {
                continue;
            }
            $index[$ref['path']][] = [
                'source' => $source,
                'entity_type' => $type,
                'id' => $id,
                'identifier' => $identifier,
                'edition' => 'live',
                'uid' => null,
                'component' => null,
                'field' => null,
                'alt' => $ref['alt'],
                'has_alt' => $ref['alt'] !== null && trim($ref['alt']) !== '',
            ];
        }
    }

    /**
     * Rows of HTML-ish EAV text attributes that contain images.
     *
     * @param string $table
     * @param string $entityTypeCode
     * @param array $attributeCodes
     * @param array $needles Only rows mentioning one of these paths; empty = no path filter.
     * @return array
     * @phpstan-param list<string> $attributeCodes
     * @phpstan-param list<string> $needles
     * @phpstan-return list<array{id: int, code: string, value: string}>
     */
    private function descriptions(string $table, string $entityTypeCode, array $attributeCodes, array $needles): array
    {
        $connection = $this->resourceConnection->getConnection();
        $attributeIds = $this->attributeIds[$entityTypeCode] ??= $connection->fetchPairs(
            $connection->select()
                ->from(
                    ['a' => $this->resourceConnection->getTableName('eav_attribute')],
                    ['attribute_id', 'attribute_code']
                )
                ->join(
                    ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', $entityTypeCode)
                ->where('a.attribute_code IN (?)', $attributeCodes)
        );
        if ($attributeIds === []) {
            return [];
        }
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName($table), ['entity_id', 'attribute_id', 'value'])
            ->where('attribute_id IN (?)', array_keys($attributeIds))
            ->where(
                $connection->quoteInto('value LIKE ?', '%<img%')
                . ' OR ' . $connection->quoteInto('value LIKE ?', '%{{media%')
            );
        if ($needles !== []) {
            // The extractor decodes references, so a description linking `my%20image.jpg` is a usage of
            // `my image.jpg`; the LIKE prefilter has to look for the encoded spelling too.
            $select->where(implode(' OR ', array_map(
                static fn (string $needle): string => $connection->quoteInto(
                    'value LIKE ?',
                    '%' . addcslashes($needle, '%_') . '%'
                ),
                $this->withEncodedVariants($needles)
            )));
        }
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'id' => Cast::int($row['entity_id']),
                'code' => Cast::string($attributeIds[$row['attribute_id']] ?? ''),
                'value' => Cast::string($row['value']),
            ];
        }
        return $rows;
    }

    /**
     * The paths plus their URL-encoded spelling (per segment, slashes kept) where it differs.
     *
     * @param array $paths
     * @return array
     * @phpstan-param list<string> $paths
     * @phpstan-return list<string>
     */
    private function withEncodedVariants(array $paths): array
    {
        $needles = $paths;
        foreach ($paths as $path) {
            $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
            if ($encoded !== $path) {
                $needles[] = $encoded;
            }
        }
        return array_values(array_unique($needles));
    }
}
