<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Media;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Exception\PartialRewriteException;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for hyva_media.* tools that read media (admin Media Gallery permission).
 */
abstract class AbstractMediaTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * Resource the tool is listed with while Hyvä Media Gallery is missing or disabled: none, so tools/list hides it.
     */
    protected const FEATURE_MISSING_ACL_RESOURCE = ContentAcl::UNAVAILABLE_RESOURCE;

    /**
     * @param FeatureCheckerInterface $featureChecker
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker
    ) {
    }

    /**
     * Hyvä resource the tool is listed with.
     *
     * The Hyva_MediaGallery::* resources only exist while Media Gallery is enabled; without it the tool is hidden
     * from tools/list (FEATURE_MISSING_ACL_RESOURCE). hyva_media.status still reports the feature.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->isMediaGalleryEnabled() ? static::UNDERLYING_ACL_RESOURCE : static::FEATURE_MISSING_ACL_RESOURCE;
    }

    /**
     * Whether Hyvä Media Gallery is installed and enabled, so its Hyva_MediaGallery::* resources exist.
     *
     * @return bool
     */
    protected function isMediaGalleryEnabled(): bool
    {
        return (bool) $this->featureChecker->getStatus(FeatureCheckerInterface::MEDIA_GALLERY)['enabled'];
    }

    /**
     * Soft error for a draft rewrite that failed after some drafts were saved; a refusal keeps its `reason`.
     *
     * @param PartialRewriteException $exception
     * @return ToolResultInterface
     * @throws LocalizedException
     */
    protected function partialRewriteError(PartialRewriteException $exception): ToolResultInterface
    {
        $cause = $exception->getCause();
        return $this->softError(
            $exception->getMessage(),
            ['drafts_saved' => $exception->getSaved(), 'failed_at' => $exception->getFailedTarget()]
                + ($cause instanceof AuthorizationException ? $this->refusalData($cause) : [])
                + [
                    'published' => false,
                    'next_step' => 'Review the drafts already saved (or restore their previous version), then '
                        . 'repeat the call with entity_ids for the entities not yet changed.',
                ]
        );
    }
}
