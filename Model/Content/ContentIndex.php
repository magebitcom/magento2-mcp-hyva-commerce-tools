<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;

/**
 * Lists Magento CMS pages/blocks joined with their Hyvä CMS row, with derived content status.
 * getRows() reads whole tables for the audits; findIds()/countIds() filter and paginate in SQL for the list tools.
 *
 * @phpstan-type ContentRow array{type: string, id: int, identifier: string, title: string, is_active: bool,
 *     store_ids: list<int>, has_hyva_row: bool, liveview_enabled: bool, status: string, has_draft: bool,
 *     is_published: bool, draft_differs: bool, uses_page_builder: bool, has_magento_content: bool, updated_at: string,
 *     draft_content?: string|null, published_content?: string|null, magento_content?: string}
 * @phpstan-type ListFilters array{store_id?: int|null, visible_store_ids?: list<int>|null, search?: string|null,
 *     liveview_enabled?: bool|null, legacy_only?: bool, content?: string|null, ids?: list<int>|null,
 *     exclude_ids?: list<int>|null}
 */
class ContentIndex
{
    public const STATUS_NOT_HYVA = 'not_hyva';
    public const STATUS_DRAFT_ONLY = 'draft_only';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PUBLISHED_WITH_DRAFT = 'published_with_draft';

    /**
     * `content` filter values: entities with draft content, with published content, with both, or with neither.
     */
    public const CONTENT_DRAFT = 'draft';
    public const CONTENT_PUBLISHED = 'published';
    public const CONTENT_BOTH = 'both';
    public const CONTENT_DRAFT_ONLY = 'draft_only';
    public const CONTENT_NONE = 'none';

    /**
     * Magento content counts when it has text or an image once tags, &nbsp; and whitespace are removed.
     */
    private const HAS_MAGENTO_CONTENT = "TRIM(REGEXP_REPLACE(IFNULL(e.content, ''), '<[^>]*>|&nbsp;|[[:space:]]+', ''))"
        . " <> '' OR e.content LIKE '%<img%'";
    private const USES_PAGE_BUILDER = "e.content LIKE '%data-content-type=%'";

    private const TABLES = [
        ContentLocator::TYPE_PAGE => [
            'entity' => 'cms_page', 'id' => 'page_id', 'store' => 'cms_page_store',
            'hyva' => 'hyva_commerce_cms_page', 'link' => 'cms_page_id',
        ],
        ContentLocator::TYPE_BLOCK => [
            'entity' => 'cms_block', 'id' => 'block_id', 'store' => 'cms_block_store',
            'hyva' => 'hyva_commerce_cms_block', 'link' => 'cms_block_id',
        ],
    ];

    /**
     * Entity and store link tables per type for getStoreIds(); menus only when Hyvä Menu Builder is installed.
     */
    private const STORE_LINKS = [
        ContentLocator::TYPE_PAGE => ['entity' => 'cms_page', 'id' => 'page_id', 'store' => 'cms_page_store',
            'link' => 'page_id'],
        ContentLocator::TYPE_BLOCK => ['entity' => 'cms_block', 'id' => 'block_id', 'store' => 'cms_block_store',
            'link' => 'block_id'],
        ContentLocator::TYPE_MENU => ['entity' => 'hyva_commerce_cms_menu', 'id' => 'id',
            'store' => 'hyva_commerce_cms_menu_store', 'link' => 'menu_id'],
    ];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Get rows.
     *
     * @param string $type cms_page|cms_block
     * @param bool $withContent Include raw draft/published JSON and Magento content.
     * @param array|null $ids Only these entity ids.
     * @return array
     * @phpstan-param list<int>|null $ids
     * @phpstan-return list<ContentRow>
     */
    public function getRows(string $type, bool $withContent = false, ?array $ids = null): array
    {
        $tables = self::TABLES[$type] ?? null;
        if ($tables === null) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName($tables['entity'])], [
                'id' => $tables['id'],
                'identifier',
                'title',
                'is_active',
                'creation_time',
                'update_time',
                // One rule for both modes, so a row is legacy or not regardless of $withContent: Magento content
                // counts when it has text or an image once tags, &nbsp; and whitespace are removed.
                'uses_pb' => new Expression(self::USES_PAGE_BUILDER),
                'has_mc' => new Expression(self::HAS_MAGENTO_CONTENT),
            ] + ($withContent ? ['magento_content' => 'content'] : []))
            ->joinLeft(
                ['h' => $this->resourceConnection->getTableName($tables['hyva'])],
                sprintf('h.%s = e.%s', $tables['link'], $tables['id']),
                ['is_liveview_enabled', 'draft_content', 'published_content', 'hyva_update_time' => 'update_time']
            )
            ->order('e.' . $tables['id']);
        if ($ids !== null) {
            $select->where('e.' . $tables['id'] . ' IN (?)', $ids === [] ? [0] : $ids);
        }

        $storeSelect = $connection->select()->from(
            $this->resourceConnection->getTableName($tables['store']),
            ['entity_id' => $tables['id'], 'store_id']
        );
        if ($ids !== null) {
            $storeSelect->where($tables['id'] . ' IN (?)', $ids === [] ? [0] : $ids);
        }
        $storeRows = $connection->fetchAll($storeSelect);
        $stores = [];
        foreach ($storeRows as $storeRow) {
            $stores[Cast::int($storeRow['entity_id'])][] = Cast::int($storeRow['store_id']);
        }

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $id = Cast::int($row['id']);
            $draft = is_string($row['draft_content']) ? $row['draft_content'] : null;
            $published = is_string($row['published_content']) ? $row['published_content'] : null;
            $magentoContent = is_string($row['magento_content'] ?? null) ? $row['magento_content'] : '';
            $item = [
                'type' => $type,
                'id' => $id,
                'identifier' => Cast::string($row['identifier']),
                'title' => Cast::string($row['title']),
                'is_active' => Cast::bool($row['is_active']),
                'store_ids' => $stores[$id] ?? [],
                'has_hyva_row' => $row['is_liveview_enabled'] !== null,
                'liveview_enabled' => Cast::bool($row['is_liveview_enabled']),
                'status' => $this->status($draft, $published),
                'has_draft' => $this->hasContent($draft),
                'is_published' => $this->hasContent($published),
                'draft_differs' => $this->hasContent($draft) && !$this->sameContent($draft, $published),
                'uses_page_builder' => Cast::bool($row['uses_pb'] ?? false),
                'has_magento_content' => Cast::bool($row['has_mc'] ?? false),
                'updated_at' => Cast::string($row['hyva_update_time'] ?? $row['update_time'] ?? ''),
            ];
            if ($withContent) {
                $item['draft_content'] = $draft;
                $item['published_content'] = $published;
                $item['magento_content'] = $magentoContent;
            }
            $rows[] = $item;
        }
        return $rows;
    }

    /**
     * Ids of the entities matching the filters, in id order; one page of them when a limit is given.
     *
     * Every filter is applied in SQL, so no draft/published JSON leaves the database. `content` matches on whether
     * the draft/published JSON is non-empty; telling "published" from "published with draft changes" needs
     * sameContent() on the rows themselves.
     *
     * @param string $type cms_page|cms_block
     * @param array $filters
     * @param int|null $limit
     * @param int $offset
     * @return array
     * @phpstan-param ListFilters $filters
     * @phpstan-return list<int>
     */
    public function findIds(string $type, array $filters, ?int $limit = null, int $offset = 0): array
    {
        $tables = self::TABLES[$type] ?? null;
        if ($tables === null) {
            return [];
        }
        $select = $this->filteredSelect($type, $filters)
            ->columns(['id' => 'e.' . $tables['id']])
            ->order('e.' . $tables['id']);
        if ($limit !== null) {
            $select->limit($limit, $offset);
        }
        return array_map('intval', $this->resourceConnection->getConnection()->fetchCol($select));
    }

    /**
     * Number of entities matching the filters (see findIds()).
     *
     * @param string $type cms_page|cms_block
     * @param array $filters
     * @return int
     * @phpstan-param ListFilters $filters
     */
    public function countIds(string $type, array $filters): int
    {
        if (!isset(self::TABLES[$type])) {
            return 0;
        }
        $select = $this->filteredSelect($type, $filters)->columns(['total' => new Expression('COUNT(*)')]);
        return Cast::int($this->resourceConnection->getConnection()->fetchOne($select));
    }

    /**
     * Id, identifier, active flag and store views of the entities with these identifiers, in id order, without
     * any content column.
     *
     * @param string $type cms_page|cms_block
     * @param array $identifiers
     * @return array
     * @phpstan-param list<string> $identifiers
     * @phpstan-return list<array{id: int, identifier: string, is_active: bool, store_ids: list<int>}>
     */
    public function getByIdentifiers(string $type, array $identifiers): array
    {
        $tables = self::TABLES[$type] ?? null;
        if ($tables === null || $identifiers === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['e' => $this->resourceConnection->getTableName($tables['entity'])],
                ['id' => $tables['id'], 'identifier', 'is_active']
            )
            ->where('e.identifier IN (?)', $identifiers)
            ->order('e.' . $tables['id']);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[Cast::int($row['id'])] = [
                'id' => Cast::int($row['id']),
                'identifier' => Cast::string($row['identifier']),
                'is_active' => Cast::bool($row['is_active']),
                'store_ids' => [],
            ];
        }
        if ($rows === []) {
            return [];
        }
        $storeSelect = $connection->select()
            ->from(
                $this->resourceConnection->getTableName($tables['store']),
                ['entity_id' => $tables['id'], 'store_id']
            )
            ->where($tables['id'] . ' IN (?)', array_keys($rows));
        foreach ($connection->fetchAll($storeSelect) as $storeRow) {
            $entityId = Cast::int($storeRow['entity_id']);
            if (isset($rows[$entityId])) {
                $rows[$entityId]['store_ids'][] = Cast::int($storeRow['store_id']);
            }
        }
        return array_values($rows);
    }

    /**
     * Store views of the entities with these ids, in one query; ids that do not exist are absent from the result.
     *
     * @param string $type cms_page|cms_block|menu
     * @param array $ids
     * @return array
     * @phpstan-param list<int> $ids
     * @phpstan-return array<int, list<int>>
     */
    public function getStoreIds(string $type, array $ids): array
    {
        $tables = self::STORE_LINKS[$type] ?? null;
        if ($tables === null || $ids === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName($tables['entity'])], ['id' => $tables['id']])
            ->joinLeft(
                ['s' => $this->resourceConnection->getTableName($tables['store'])],
                sprintf('s.%s = e.%s', $tables['link'], $tables['id']),
                ['store_id']
            )
            ->where('e.' . $tables['id'] . ' IN (?)', array_values(array_unique($ids)));
        $storeIds = [];
        foreach ($connection->fetchAll($select) as $row) {
            $id = Cast::int($row['id']);
            $storeIds[$id] ??= [];
            if ($row['store_id'] !== null) {
                $storeIds[$id][] = Cast::int($row['store_id']);
            }
        }
        return $storeIds;
    }

    /**
     * Legacy = not rendered by Hyvä CMS (liveview off) while Magento content exists.
     *
     * @param ContentRow $row
     * @return bool
     */
    public function isLegacy(array $row): bool
    {
        return !$row['liveview_enabled'] && ($row['has_magento_content'] || $row['uses_page_builder']);
    }

    /**
     * Compare only the component trees; the `version` block changes on every save.
     *
     * @param string|null $left
     * @param string|null $right
     * @return bool
     */
    public function sameContent(?string $left, ?string $right): bool
    {
        return $this->contentOf($left) === $this->contentOf($right);
    }

    /**
     * Entity select (alias e, Hyvä row alias h, no columns) with the list filters applied.
     *
     * @param string $type
     * @param array $filters
     * @return Select
     * @phpstan-param ListFilters $filters
     */
    private function filteredSelect(string $type, array $filters): Select
    {
        $tables = self::TABLES[$type];
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName($tables['entity'])], [])
            ->joinLeft(
                ['h' => $this->resourceConnection->getTableName($tables['hyva'])],
                sprintf('h.%s = e.%s', $tables['link'], $tables['id']),
                []
            );

        $ids = $filters['ids'] ?? null;
        if ($ids !== null) {
            $select->where($ids === [] ? '1 = 0' : $connection->quoteInto('e.' . $tables['id'] . ' IN (?)', $ids));
        }
        $excludeIds = $filters['exclude_ids'] ?? [];
        if ($excludeIds !== []) {
            $select->where('e.' . $tables['id'] . ' NOT IN (?)', $excludeIds);
        }
        $storeId = $filters['store_id'] ?? null;
        if ($storeId !== null) {
            $select->where($this->storeLinkCondition($connection, $type, [$storeId, 0]));
        }
        $visibleStoreIds = $filters['visible_store_ids'] ?? null;
        if ($visibleStoreIds !== null) {
            $select->where($this->storeLinkCondition($connection, $type, $visibleStoreIds));
        }
        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $select->where(
                "CONCAT(IFNULL(e.title, ''), ' ', IFNULL(e.identifier, '')) LIKE ?",
                '%' . addcslashes($search, '%_\\') . '%'
            );
        }
        $liveview = $filters['liveview_enabled'] ?? null;
        if ($liveview !== null) {
            $select->where('IFNULL(h.is_liveview_enabled, 0) ' . ($liveview ? '<> 0' : '= 0'));
        }
        if ($filters['legacy_only'] ?? false) {
            $select->where(sprintf(
                'IFNULL(h.is_liveview_enabled, 0) = 0 AND ((%s) OR (%s))',
                self::USES_PAGE_BUILDER,
                self::HAS_MAGENTO_CONTENT
            ));
        }
        $content = $filters['content'] ?? null;
        if ($content !== null) {
            $hasDraft = "IFNULL(TRIM(h.draft_content), '') NOT IN ('', '[]')";
            $hasPublished = "IFNULL(TRIM(h.published_content), '') NOT IN ('', '[]')";
            $select->where(match ($content) {
                self::CONTENT_DRAFT => $hasDraft,
                self::CONTENT_PUBLISHED => $hasPublished,
                self::CONTENT_BOTH => $hasDraft . ' AND ' . $hasPublished,
                self::CONTENT_DRAFT_ONLY => $hasDraft . ' AND NOT (' . $hasPublished . ')',
                default => 'NOT (' . $hasDraft . ') AND NOT (' . $hasPublished . ')',
            });
        }
        return $select;
    }

    /**
     * EXISTS condition: the entity is assigned to one of the store views.
     *
     * @param AdapterInterface $connection
     * @param string $type
     * @param array $storeIds
     * @return string
     * @phpstan-param list<int> $storeIds
     */
    private function storeLinkCondition(AdapterInterface $connection, string $type, array $storeIds): string
    {
        $tables = self::TABLES[$type];
        return sprintf(
            'EXISTS (SELECT 1 FROM %s AS store_link WHERE store_link.%s = e.%s AND %s)',
            $connection->quoteIdentifier($this->resourceConnection->getTableName($tables['store'])),
            $tables['id'],
            $tables['id'],
            $connection->quoteInto('store_link.store_id IN (?)', $storeIds === [] ? [-1] : $storeIds)
        );
    }

    /**
     * Status.
     *
     * @param string|null $draft
     * @param string|null $published
     * @return string
     */
    private function status(?string $draft, ?string $published): string
    {
        if (!$this->hasContent($draft) && !$this->hasContent($published)) {
            return self::STATUS_NOT_HYVA;
        }
        if (!$this->hasContent($published)) {
            return self::STATUS_DRAFT_ONLY;
        }
        return $this->hasContent($draft) && !$this->sameContent($draft, $published)
            ? self::STATUS_PUBLISHED_WITH_DRAFT
            : self::STATUS_PUBLISHED;
    }

    /**
     * Has content.
     *
     * @param string|null $json
     * @return bool
     */
    private function hasContent(?string $json): bool
    {
        return $json !== null && trim($json) !== '' && trim($json) !== '[]';
    }

    /**
     * Content of.
     *
     * @param string|null $json
     * @return string
     */
    private function contentOf(?string $json): string
    {
        if (!$this->hasContent($json)) {
            return '';
        }
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            return (string) $json;
        }
        return (string) json_encode($data['content'] ?? null);
    }
}
