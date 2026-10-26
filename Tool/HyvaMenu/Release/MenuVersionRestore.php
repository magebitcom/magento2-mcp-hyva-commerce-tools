<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Release;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Version\VersionRestore;

/**
 * `hyva_menu.version.restore`: VersionRestore bound to menus (Menu Builder 1.1 per-verb permissions).
 */
class MenuVersionRestore extends VersionRestore
{
    public const TOOL_NAME = 'hyva_menu.version.restore';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_version_restore';
    public const FIXED_CONTENT_TYPE = ContentLocator::TYPE_MENU;

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Restore Hyvä Menu Version';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Restore an older menu version as the new DRAFT (nothing goes live until hyva_menu.publish).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withoutEntityType(parent::getInputSchema());
    }
}
