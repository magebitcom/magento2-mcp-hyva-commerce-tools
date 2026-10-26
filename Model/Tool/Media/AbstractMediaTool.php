<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Media;

use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

/**
 * Base for hyva_media.* tools that read media (admin Media Gallery permission).
 */
abstract class AbstractMediaTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';
}
