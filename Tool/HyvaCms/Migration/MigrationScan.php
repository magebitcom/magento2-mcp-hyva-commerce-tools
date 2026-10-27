<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Migration;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Migration\MigrationService;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * `hyva_cms.migration.scan` MCP tool.
 */
class MigrationScan extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.migration.scan';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_migration_scan';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @param MigrationService $migrationService
     * @param ContentAcl $contentAcl
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        private readonly MigrationService $migrationService,
        private readonly ContentAcl $contentAcl,
        private readonly StoreScopeGuard $storeScopeGuard
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Scan Legacy CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Find CMS pages and blocks still rendered from Magento content (Page Builder or WYSIWYG) instead of '
            . 'Hyvä CMS, ranked by migration complexity (elements, HTML fallbacks, images). Also flags converted '
            . 'drafts that are waiting for review/publish.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('entity_type', fn (StringBuilder $s) => $s
                ->enum([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK]))
            ->string('kind', fn (StringBuilder $s) => $s->enum(['page_builder', 'wysiwyg', 'empty']))
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(500)
                ->description('Maximum items returned (default 50); the summary always counts every legacy row.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->stringArg($arguments, 'entity_type');
        $rows = $this->migrationService->scan(
            $type !== null ? [$type] : [ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK],
            // Pages and blocks outside the caller's website/store view restriction are not listed.
            fn (array $storeIds): bool => $this->storeScopeGuard->allowsRead($storeIds)
        );
        $kind = $this->stringArg($arguments, 'kind');
        if ($kind !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === $kind));
        }
        $summary = [
            'total' => count($rows),
            'page_builder' => 0,
            'wysiwyg' => 0,
            'empty' => 0,
            'converted_drafts_waiting' => 0,
        ];
        foreach ($rows as $row) {
            $summary[Cast::string($row['kind'])]++;
            $summary['converted_drafts_waiting'] += $row['converted_draft_waiting'] ? 1 : 0;
        }
        return $this->jsonResult([
            'summary' => $summary,
            'items' => array_slice($rows, 0, $this->intArg($arguments, 'limit', 50) ?? 50),
        ], $summary);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }

    /**
     * @inheritDoc
     */
    protected function targetContentTypes(array $arguments): array
    {
        return parent::targetContentTypes($arguments) ?: [ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK];
    }
}
