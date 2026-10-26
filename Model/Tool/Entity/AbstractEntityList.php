<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Preset\Pagination;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared implementation of hyva_cms.page.list / hyva_cms.block.list.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 */
abstract class AbstractEntityList extends AbstractTool
{
    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';

    /**
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param ScheduleService $scheduleService
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ScheduleService $scheduleService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä CMS ' . static::ENTITY_LABEL . 's';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'List CMS %ss with their Hyvä CMS state: liveview enabled, published, draft-only or published with '
                . 'unpublished draft changes, pending schedules, and whether the Magento content still uses Page '
                . 'Builder/WYSIWYG. Filter by store view, status, component usage or legacy (non-Hyvä) content.',
            strtolower(static::ENTITY_LABEL)
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('store_id', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Only entities assigned to this store view (or to all stores).'))
            ->string('status', fn (StringBuilder $s) => $s
                ->enum(
                    [
                        ContentIndex::STATUS_NOT_HYVA,
                        ContentIndex::STATUS_DRAFT_ONLY,
                        ContentIndex::STATUS_PUBLISHED,
                        ContentIndex::STATUS_PUBLISHED_WITH_DRAFT,
                        'has_draft',
                    ]
                )
                ->description('Hyvä content state. has_draft = draft_only or published_with_draft.'))
            ->boolean('liveview_enabled', fn (BooleanBuilder $b) => $b
                ->description('Filter on the Hyvä CMS (liveview) flag.'))
            ->boolean('scheduled', fn (BooleanBuilder $b) => $b
                ->description('Only entities with (true) / without (false) a pending scheduled release.'))
            ->string('component', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Only entities whose draft or published content uses this component, e.g. "banner".'))
            ->boolean('legacy_only', fn (BooleanBuilder $b) => $b
                ->description('Only entities still rendered from Magento content (Page Builder / WYSIWYG).'))
            ->string('search', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Substring of title or identifier.'))
            ->with(Pagination::maxPageSize(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $component = $this->stringArg($arguments, 'component');
        $rows = $this->contentIndex->getRows(static::ENTITY_TYPE, $component !== null);
        $scheduled = $this->scheduleService->getPendingEntityIds(static::ENTITY_TYPE);

        $storeId = $this->intArg($arguments, 'store_id');
        $status = $this->stringArg($arguments, 'status');
        $search = $this->stringArg($arguments, 'search');
        $filtered = [];
        foreach ($rows as $row) {
            if ($storeId !== null && !in_array(
                $storeId,
                $row['store_ids'],
                true
            ) && !in_array(0, $row['store_ids'], true)
            ) {
                continue;
            }
            if ($status === 'has_draft' && !in_array(
                $row['status'],
                [ContentIndex::STATUS_DRAFT_ONLY, ContentIndex::STATUS_PUBLISHED_WITH_DRAFT],
                true
            )
            ) {
                continue;
            }
            if ($status !== null && $status !== 'has_draft' && $row['status'] !== $status) {
                continue;
            }
            if (array_key_exists(
                'liveview_enabled',
                $arguments
            ) && $row['liveview_enabled'] !== $this->boolArg($arguments, 'liveview_enabled')
            ) {
                continue;
            }
            $isScheduled = in_array($row['id'], $scheduled, true);
            if (array_key_exists('scheduled', $arguments) && $isScheduled !== $this->boolArg($arguments, 'scheduled')) {
                continue;
            }
            if ($this->boolArg($arguments, 'legacy_only') && !$this->contentIndex->isLegacy($row)) {
                continue;
            }
            if ($search !== null && stripos($row['title'] . ' ' . $row['identifier'], $search) === false) {
                continue;
            }
            if ($component !== null && !$this->usesComponent($row, $component)) {
                continue;
            }
            unset($row['draft_content'], $row['published_content'], $row['magento_content']);
            $row['scheduled'] = $isScheduled;
            $row['legacy'] = $this->contentIndex->isLegacy($row);
            $filtered[] = $row;
        }

        $page = max(1, $this->intArg($arguments, 'page', 1) ?? 1);
        $pageSize = min(200, max(1, $this->intArg($arguments, 'page_size', 50) ?? 50));
        $items = array_slice($filtered, ($page - 1) * $pageSize, $pageSize);

        return $this->jsonResult(
            ['total' => count($filtered), 'page' => $page, 'page_size' => $pageSize, 'items' => $items],
            ['total' => count($filtered)]
        );
    }

    /**
     * Uses component.
     *
     * @param ContentRow $row
     * @param string $component
     * @return bool
     */
    private function usesComponent(array $row, string $component): bool
    {
        foreach (['draft_content', 'published_content'] as $column) {
            $json = $row[$column] ?? null;
            if (!is_string($json) || !str_contains($json, '"' . $component . '"')) {
                continue;
            }
            try {
                $counts = $this->componentTree->countComponents(
                    $this->componentTree->parse($json, static::ENTITY_TYPE, $row['id'])
                );
            } catch (LocalizedException) {
                continue;
            }
            if (isset($counts[$component])) {
                return true;
            }
        }
        return false;
    }
}
