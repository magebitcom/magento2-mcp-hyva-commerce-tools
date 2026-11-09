<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Exception\AclRefusalException;
use Magebit\McpHyvaCommerceTools\Exception\PartialRewriteException;

/**
 * `hyva_media.image.update_alt` MCP tool.
 */
class ImageUpdateAlt extends AbstractMediaTool implements ContextualAclAwareInterface
{
    public const TOOL_NAME = 'hyva_media.image.update_alt';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_update_alt';
    public const IS_WRITE = true;
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param ImageContentEditor $editor
     * @param ContentAcl $contentAcl
     * @param ContentLocator $contentLocator
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        private readonly ImageContentEditor $editor,
        private readonly ContentAcl $contentAcl,
        private readonly ContentLocator $contentLocator,
        private readonly ContentScopeFilter $scopeFilter
    ) {
        parent::__construct($featureChecker);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Media Image Alt Text';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set the alt text of an image wherever it is used in Hyvä CMS pages, blocks and menus (image fields '
            . 'and <img> tags in rich text/HTML), saved as DRAFTS. Limit with scope and entity_ids. Never publishes. '
            . 'Needs no confirm: the drafts are written on the first call and the reply says dry_run: false.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('path', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->required()
                ->description('Media path (e.g. wysiwyg/banner.jpg), /media/... path or media URL.'))
            ->string('alt', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['cms_page', 'cms_block', 'menu', 'all'])
                ->description('Default all.'))
            ->array('entity_ids', fn (ArrayBuilder $a) => $a->ofIntegers())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Not needed and ignored: alt text is saved as drafts, which go live only with hyva_cms_publish.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $path = $this->editor->normalize($this->requireString($arguments, 'path'));
        $scope = $this->stringArg($arguments, 'scope', 'all') ?? 'all';
        $requested = $scope === 'all' ? ContentLocator::TYPES : [$scope];
        $types = $this->contentAcl->allowedTypes($requested, ContentAcl::VERB_SAVE);
        if ($types === []) {
            throw new AclRefusalException(
                __('Your role may not save %1 drafts.', implode(' / ', $requested)),
                $this->contentAcl->resources($requested, [ContentAcl::VERB_SAVE])
            );
        }
        $targets = $this->editor->findTargets($path, $types, $this->intListArg($arguments, 'entity_ids'));
        // A role restricted to some websites or store views only changes the content it may change.
        $inScope = $this->contentLocator->partitionByScope($targets['editable']);
        $targets['editable'] = $inScope['writable'];
        $outOfScope = $inScope['read_only'] !== [] ? ['skipped_out_of_scope' => $inScope['read_only']] : [];
        // Usages that are only reported (Magento CMS, catalog) are listed only for content the role may see.
        $notEditable = $this->scopeFilter->filter($targets['not_editable']);
        $targets['not_editable'] = $notEditable['visible'];
        $outOfScope = $this->scopeFilter->withHidden(
            $outOfScope,
            'usages_hidden_by_permissions',
            $notEditable['hidden']
        );
        if ($targets['editable'] === []) {
            return $this->softError(
                sprintf('"%s" is not used in Hyvä content for this scope.', $path),
                ['not_editable_usages' => $targets['not_editable']] + $outOfScope
            );
        }
        try {
            $results = $this->editor->rewrite(
                $targets['editable'],
                $this->editor->altRewrite($path, $this->requireString($arguments, 'alt')),
                'Alt text updated (MCP)'
            );
        } catch (PartialRewriteException $exception) {
            return $this->partialRewriteError($exception);
        }
        return $this->jsonResult(
            [
                'dry_run' => false,
                'path' => $path,
                'drafts' => $results,
                'published' => false,
                'not_changed' => $targets['not_editable'],
                'skipped_types_no_permission' => array_values(array_diff($requested, $types)),
            ] + $outOfScope,
            ['path' => $path, 'entities' => count($results)]
        );
    }

    /**
     * Listed only for a role that may save drafts of at least one content type, besides Media Gallery view.
     *
     * Hidden from tools/list while Media Gallery is missing or disabled (FEATURE_MISSING_ACL_RESOURCE).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        if (!$this->isMediaGalleryEnabled()) {
            return static::FEATURE_MISSING_ACL_RESOURCE;
        }
        return $this->contentAcl->listingResource(
            ContentLocator::TYPES,
            [ContentAcl::VERB_SAVE],
            [static::UNDERLYING_ACL_RESOURCE],
            static::UNDERLYING_ACL_RESOURCE
        );
    }

    /**
     * Save permission on the content type when scope names one; "global"/"all" is filtered per type in run().
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    public function getContextualAclResources(array $arguments): array
    {
        $scope = $arguments['scope'] ?? null;
        return is_string($scope) && in_array($scope, ContentLocator::TYPES, true)
            ? $this->contentAcl->resources([$scope], [ContentAcl::VERB_SAVE])
            : [];
    }
}
