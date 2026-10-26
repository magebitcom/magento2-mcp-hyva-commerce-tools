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
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Preset\Pagination;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magento\Framework\Exception\LocalizedException;

/**
 * Shared implementation of hyva_cms.page.list / hyva_cms.block.list. Filters and pagination run in SQL; only a
 * status that compares draft with published, or a component filter, reads the content of the candidates (in
 * chunks). Entities outside the caller's website/store view restriction are not listed.
 *
 * @phpstan-import-type ContentRow from ContentIndex
 * @phpstan-import-type ListFilters from ContentIndex
 */
abstract class AbstractEntityList extends AbstractTool
{
    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';

    /**
     * Status filter => the SQL content prefilter; not_hyva and draft_only need nothing else.
     */
    private const STATUS_CONTENT = [
        ContentIndex::STATUS_NOT_HYVA => ContentIndex::CONTENT_NONE,
        ContentIndex::STATUS_DRAFT_ONLY => ContentIndex::CONTENT_DRAFT_ONLY,
        ContentIndex::STATUS_PUBLISHED => ContentIndex::CONTENT_PUBLISHED,
        ContentIndex::STATUS_PUBLISHED_WITH_DRAFT => ContentIndex::CONTENT_BOTH,
        'has_draft' => ContentIndex::CONTENT_DRAFT,
    ];
    private const STATUS_EXACT_IN_SQL = [ContentIndex::STATUS_NOT_HYVA, ContentIndex::STATUS_DRAFT_ONLY];
    private const CHUNK_SIZE = 100;

    /**
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param ScheduleService $scheduleService
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ScheduleService $scheduleService,
        private readonly StoreScopeGuard $storeScopeGuard
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
        $type = static::ENTITY_TYPE;
        $component = $this->stringArg($arguments, 'component');
        $status = $this->stringArg($arguments, 'status');
        $scheduled = $this->scheduleService->getPendingEntityIds($type);
        $filters = $this->filters($arguments, $status, $scheduled);

        $page = max(1, $this->intArg($arguments, 'page', 1) ?? 1);
        $pageSize = min(200, max(1, $this->intArg($arguments, 'page_size', 50) ?? 50));
        $offset = ($page - 1) * $pageSize;

        if ($component === null && ($status === null || in_array($status, self::STATUS_EXACT_IN_SQL, true))) {
            $total = $this->contentIndex->countIds($type, $filters);
            $ids = $this->contentIndex->findIds($type, $filters, $pageSize, $offset);
            $rows = $ids === [] ? [] : $this->contentIndex->getRows($type, false, $ids);
        } else {
            // Candidates come from SQL; the draft/published comparison and the component search need the JSON.
            $total = 0;
            $rows = [];
            foreach (array_chunk($this->contentIndex->findIds($type, $filters), self::CHUNK_SIZE) as $chunk) {
                foreach ($this->contentIndex->getRows($type, $component !== null, $chunk) as $row) {
                    if (!$this->matchesStatus($row, $status)
                        || ($component !== null && !$this->usesComponent($row, $component))
                    ) {
                        continue;
                    }
                    if ($total >= $offset && count($rows) < $pageSize) {
                        $rows[] = $row;
                    }
                    $total++;
                }
            }
        }

        $items = [];
        foreach ($rows as $row) {
            unset($row['draft_content'], $row['published_content'], $row['magento_content']);
            $row['scheduled'] = in_array($row['id'], $scheduled, true);
            $row['legacy'] = $this->contentIndex->isLegacy($row);
            $items[] = $row;
        }

        return $this->jsonResult(
            ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'items' => $items],
            ['total' => $total]
        );
    }

    /**
     * SQL filters from the tool arguments and the caller's store view restriction.
     *
     * @param array $arguments
     * @param string|null $status
     * @param array $scheduled Ids with a pending schedule.
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-param list<int> $scheduled
     * @phpstan-return ListFilters
     */
    private function filters(array $arguments, ?string $status, array $scheduled): array
    {
        $filters = [
            'store_id' => $this->intArg($arguments, 'store_id'),
            'visible_store_ids' => $this->storeScopeGuard->visibleStoreIds(),
            'search' => $this->stringArg($arguments, 'search'),
            'liveview_enabled' => array_key_exists('liveview_enabled', $arguments)
                ? $this->boolArg($arguments, 'liveview_enabled')
                : null,
            'legacy_only' => $this->boolArg($arguments, 'legacy_only'),
            'content' => $status !== null ? (self::STATUS_CONTENT[$status] ?? null) : null,
        ];
        if (array_key_exists('scheduled', $arguments)) {
            if ($this->boolArg($arguments, 'scheduled')) {
                $filters['ids'] = $scheduled;
            } else {
                $filters['exclude_ids'] = $scheduled;
            }
        }
        return $filters;
    }

    /**
     * Whether the row has the requested status.
     *
     * @param ContentRow $row
     * @param string|null $status
     * @return bool
     */
    private function matchesStatus(array $row, ?string $status): bool
    {
        if ($status === null) {
            return true;
        }
        if ($status === 'has_draft') {
            return in_array(
                $row['status'],
                [ContentIndex::STATUS_DRAFT_ONLY, ContentIndex::STATUS_PUBLISHED_WITH_DRAFT],
                true
            );
        }
        return $row['status'] === $status;
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
