<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCreate;

/**
 * `hyva_menu.schedule.create`: ScheduleCreate bound to menus (Menu Builder 1.1 per-verb permissions).
 */
class MenuScheduleCreate extends ScheduleCreate
{
    public const TOOL_NAME = 'hyva_menu.schedule.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_schedule_create';
    public const FIXED_CONTENT_TYPE = ContentLocator::TYPE_MENU;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Schedule Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Schedule the menu draft (or a version) to go live at a set time in a release, like '
            . 'hyva_cms.schedule.create with entity_type=menu.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withoutEntityType(parent::getInputSchema());
    }
}
