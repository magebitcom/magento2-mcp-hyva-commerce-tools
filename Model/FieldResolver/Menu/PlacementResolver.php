<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ScheduleService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;

/**
 * Where the menu is assigned in design config (header/footer) and pending schedules. Design config scopes a
 * restricted role may not open are left out and counted.
 */
class PlacementResolver implements ContentFieldResolverInterface
{
    public const KEY = 'placement';

    /**
     * @param MenuService $menuService
     * @param ScheduleService $scheduleService
     */
    public function __construct(
        private readonly MenuService $menuService,
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
        $assignments = $this->menuService->filterAssignments(
            $this->menuService->getAssignments($entity['identifier'])
        );
        $placement = [
            'design_config' => $assignments['visible'],
            'scheduled' => $this->scheduleService->getItems(['entity_type' => 'menu', 'entity_id' => $entity['id']]),
        ];
        if ($assignments['hidden'] > 0) {
            $placement['design_config_hidden_by_permissions'] = $assignments['hidden'];
        }
        return $placement;
    }
}
