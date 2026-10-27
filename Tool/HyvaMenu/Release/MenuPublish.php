<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content\Publish;

/**
 * `hyva_menu.publish`: Publish bound to menus (Menu Builder 1.1 per-verb permissions).
 */
class MenuPublish extends Publish
{
    public const TOOL_NAME = 'hyva_menu.publish';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_publish';
    public const FIXED_CONTENT_TYPE = ContentLocator::TYPE_MENU;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Publish Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Make a menu LIVE: publish its current draft (or a version_id from hyva_menu.version.list). Same as '
            . 'hyva_cms.publish with entity_type=menu; needs Hyva_MenuBuilder::menu_publish.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withoutEntityType(parent::getInputSchema());
    }
}
