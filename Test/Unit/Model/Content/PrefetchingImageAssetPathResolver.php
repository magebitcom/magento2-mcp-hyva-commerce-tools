<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\Api\ImageAssetPathResolverInterface;
use Hyva\CmsLiveviewEditor\Api\ImageAssetPrefetchInterface;

/**
 * Lets PHPUnit 9 mock a resolver that also prefetches, which it can't do with an interface intersection.
 */
interface PrefetchingImageAssetPathResolver extends ImageAssetPathResolverInterface, ImageAssetPrefetchInterface
{
}
