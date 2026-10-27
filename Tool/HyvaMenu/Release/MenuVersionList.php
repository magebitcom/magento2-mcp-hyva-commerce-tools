<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Version\VersionList;

/**
 * `hyva_menu.version.list`: VersionList bound to menus (Menu Builder 1.1 per-verb permissions).
 */
class MenuVersionList extends VersionList
{
    public const TOOL_NAME = 'hyva_menu.version.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_version_list';
    public const FIXED_CONTENT_TYPE = ContentLocator::TYPE_MENU;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Menu Versions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Version history of a menu, newest first (same as hyva_cms.version.list with entity_type=menu).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withoutEntityType(parent::getInputSchema());
    }
}
