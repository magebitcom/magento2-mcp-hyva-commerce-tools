<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Schedule\ScheduleCancel;

/**
 * `hyva_menu.schedule.cancel`: ScheduleCancel bound to menus (Menu Builder 1.1 per-verb permissions).
 */
class MenuScheduleCancel extends ScheduleCancel
{
    public const TOOL_NAME = 'hyva_menu.schedule.cancel';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_schedule_cancel';
    public const FIXED_CONTENT_TYPE = ContentLocator::TYPE_MENU;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Cancel Hyvä Menu Schedule';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Cancel scheduled menu items or a whole release (every item must be one the role may cancel).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withoutEntityType(parent::getInputSchema());
    }
}
