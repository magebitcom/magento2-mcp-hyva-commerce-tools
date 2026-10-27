<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentIndex;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Hyvä CMS state: liveview flag, draft/published status, pending schedules.
 */
class StatusResolver implements ContentFieldResolverInterface
{
    public const KEY = 'status';

    /**
     * @param ContentIndex $contentIndex
     * @param ScheduleService $scheduleService
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ScheduleService $scheduleService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 20;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        $status = [];
        if ($entity['type'] !== 'menu') {
            foreach ($this->contentIndex->getRows($entity['type'], false, [$entity['id']]) as $row) {
                if ($row['id'] === $entity['id']) {
                    $status = array_intersect_key($row, array_flip([
                        'liveview_enabled', 'status', 'has_draft', 'is_published', 'draft_differs',
                        'uses_page_builder', 'updated_at',
                    ]));
                    $status['legacy'] = $this->contentIndex->isLegacy($row);
                }
            }
        }
        $status['scheduled'] = $this->scheduleService->getItems(
            ['entity_type' => $entity['type'], 'entity_id' => $entity['id']]
        );
        return $status;
    }
}
