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
use Magento\Framework\DB\Sql\Expression;

/**
 * Lists Magento CMS pages/blocks joined with their Hyvä CMS row, with derived content status.
 * One query per type; the demo-scale and admin-scale data sets make in-PHP filtering acceptable.
 *
 * @phpstan-type ContentRow array{type: string, id: int, identifier: string, title: string, is_active: bool,
 *     store_ids: list<int>, has_hyva_row: bool, liveview_enabled: bool, status: string, has_draft: bool,
 *     is_published: bool, draft_differs: bool, uses_page_builder: bool, has_magento_content: bool, updated_at: string,
 *     draft_content?: string|null, published_content?: string|null, magento_content?: string}
 */
class ContentIndex
{
    public const STATUS_NOT_HYVA = 'not_hyva';
    public const STATUS_DRAFT_ONLY = 'draft_only';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PUBLISHED_WITH_DRAFT = 'published_with_draft';

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
                'uses_pb' => new Expression("e.content LIKE '%data-content-type=%'"),
                'has_mc' => new Expression(
                    "TRIM(REGEXP_REPLACE(IFNULL(e.content, ''), '<[^>]*>|&nbsp;|[[:space:]]+', '')) <> ''"
                    . " OR e.content LIKE '%<img%'"
                ),
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
