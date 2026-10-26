<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ConflictReport;
use Magebit\McpHyvaCommerceTools\Model\Schedule\ScheduleTime;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.schedule.create`: schedule a draft (or version) for publication at a future time.
 */
class ScheduleCreate extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.schedule.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_schedule_create';
    public const IS_WRITE = true;
    public const CONTENT_VERBS = [ContentAcl::VERB_SCHEDULE_SAVE];

    /**
     * @param ContentLocator $contentLocator
     * @param ScheduleService $scheduleService
     * @param ScheduleTime $scheduleTime
     * @param ConflictReport $conflictReport
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ScheduleService $scheduleService,
        private readonly ScheduleTime $scheduleTime,
        private readonly ConflictReport $conflictReport,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Schedule Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Schedule the current draft (or version_id) of a page, block or menu to be published automatically by '
            . 'the Hyvä scheduling cron. Add it to an existing pending release (release_id) or create a new release '
            . '(release_name + scheduled_at in store time, YYYY-MM-DD HH:MM). Hyvä releases have no end date: to end '
            . 'a campaign, schedule the previous content in a later release. Returns conflicts for this entity.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('release_id', fn (IntegerBuilder $i) => $i->minimum(1)->description('Existing pending release.'))
            ->string('release_name', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->maxLength(255)
                ->description('Name of a new release.'))
            ->string('scheduled_at', fn (StringBuilder $s) => $s
                ->description('New release time, store timezone (or "timezone"), YYYY-MM-DD HH:MM.'))
            ->string('timezone', fn (StringBuilder $s) => $s
                ->description('IANA timezone for scheduled_at, e.g. Europe/Riga.'))
            ->string('name', fn (StringBuilder $s) => $s
                ->maxLength(255)
                ->description('Item label; defaults to the entity title.'))
            ->integer('version_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Schedule this version instead of the current draft.'))
            ->rawProperty(
                'settings',
                [
                    'type' => 'object',
                    'description' => 'Entity attributes applied at publish time, e.g. {"is_active": true, "title": '
                        . '"..."} (page: is_active, title required together).',
                ]
            )
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locateForWrite($this->requireString($arguments, 'entity_type'), $arguments);
        $releaseId = $this->intArg($arguments, 'release_id');
        $release = ['release_id' => $releaseId];
        if ($releaseId === null) {
            $release['release_name'] = $this->requireString($arguments, 'release_name');
            $release['time_utc'] = $this->scheduleTime->toUtc(
                $this->requireString($arguments, 'scheduled_at'),
                $this->stringArg($arguments, 'timezone')
            );
        }
        $settings = $this->arrayArg($arguments, 'settings');
        $stringKeyed = [];
        foreach ($settings as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }

        $result = $this->scheduleService->schedule(
            $entity['type'],
            $entity['id'],
            $this->stringArg($arguments, 'name', $entity['title']) ?? $entity['title'],
            $release,
            $this->intArg($arguments, 'version_id'),
            $stringKeyed !== [] ? $stringKeyed : null
        );

        return $this->jsonResult([
            'scheduled' => true,
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'published_now' => false,
            'note' => 'JIT Tailwind CSS is not generated server-side; classes not already compiled for the theme may '
                . 'be missing when this release publishes.',
            'conflicts' => $this->conflictReport->withinCallerScope(
                $this->conflictReport->getConflicts(60, $entity['type'], $entity['id'])
            ),
        ] + $result, ['entity_type' => $entity['type'], 'id' => $entity['id'], 'release_id' => $result['release_id']]);
    }

    /**
     * Schedule save; scheduling a version_id also reads version history.
     *
     * @inheritDoc
     */
    protected function contentVerbs(array $arguments): array
    {
        $verbs = parent::contentVerbs($arguments);
        if (($arguments['version_id'] ?? null) !== null) {
            $verbs[] = ContentAcl::VERB_VERSION_HISTORY;
        }
        return $verbs;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
