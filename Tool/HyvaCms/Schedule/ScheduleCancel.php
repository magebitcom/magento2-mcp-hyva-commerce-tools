<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.schedule.cancel`: cancel scheduled items or a whole release; content stays untouched.
 */
class ScheduleCancel extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.schedule.cancel';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_schedule_cancel';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SCHEDULE_CANCEL];

    /**
     * @param ScheduleService $scheduleService
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Cancel Hyvä CMS Schedule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cancel pending scheduled items (item_ids) or a whole release (release_id). Drafts, versions and live '
            . 'content are not changed; a release with no pending items left is cancelled too.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->array('item_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers()
                ->description('Scheduled item ids (from hyva_cms.schedule.list).'))
            ->integer('release_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $itemIds = $this->intListArg($arguments, 'item_ids');
        $releaseId = $this->intArg($arguments, 'release_id');
        if ($itemIds === [] && $releaseId === null) {
            return $this->softError('Pass item_ids and/or release_id.');
        }
        $result = $this->scheduleService->cancel(
            $itemIds,
            $releaseId,
            // A variant bound to one content type (hyva_menu.schedule.cancel) cancels nothing else.
            fn (string $type): bool => (static::FIXED_CONTENT_TYPE === '' || $type === static::FIXED_CONTENT_TYPE)
                && $this->contentAcl->isAllowed($type, ContentAcl::VERB_SCHEDULE_CANCEL)
        );
        return $this->jsonResult($result + ['content_changed' => false], [
            'items' => count($result['cancelled_items']),
            'releases' => count($result['cancelled_releases']),
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }

    /**
     * Content types the call targets.
     *
     * Release items can be of any type; run() checks schedule_cancel per item type.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function targetContentTypes(array $arguments): array
    {
        return static::FIXED_CONTENT_TYPE !== '' ? [static::FIXED_CONTENT_TYPE] : [];
    }
}
