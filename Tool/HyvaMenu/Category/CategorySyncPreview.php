<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Category;

/**
 * `hyva_menu.category.sync_preview`: read-only preview of hyva_menu.category.import.
 */
class CategorySyncPreview extends CategoryImport
{
    public const TOOL_NAME = 'hyva_menu.category.sync_preview';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_category_sync_preview';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_view';
    public const IS_WRITE = false;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Preview Category Import into Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Preview a category import/sync without changing anything: items to create, categories already in the '
            . 'menu, inactive and hidden categories, missing URL rewrites, duplicate labels and the expected '
            . 'structure.';
    }

    /**
     * @inheritDoc
     */
    protected function isDryRun(array $arguments): bool
    {
        return true;
    }
}
