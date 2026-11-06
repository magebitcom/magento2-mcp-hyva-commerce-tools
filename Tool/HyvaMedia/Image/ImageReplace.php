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
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.image.replace` MCP tool.
 *
 * @phpstan-import-type ImageUsage from ImageUsageIndex
 */
class ImageReplace extends AbstractMediaTool implements ContextualAclAwareInterface
{
    public const TOOL_NAME = 'hyva_media.image.replace';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_replace';
    public const IS_WRITE = true;
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param ImageContentEditor $editor
     * @param MediaFileInfo $fileInfo
     * @param ContentAcl $contentAcl
     * @param ContentLocator $contentLocator
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        private readonly ImageContentEditor $editor,
        private readonly MediaFileInfo $fileInfo,
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
        return 'Replace Media Image';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Replace an image path with another in Hyvä CMS pages, blocks and menus. Changes are saved as DRAFTS '
            . '(component structure kept; optional new alt text) and go live only with hyva_cms_publish. Usages in '
            . 'Magento CMS content or product/category descriptions are reported, not changed. Without "confirm": '
            . 'true nothing changes and the reply lists the entities it would change (dry_run: true); call again '
            . 'with confirm to save the drafts. The result is a draft: publish each entity with hyva_cms_publish.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('old_path', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->string('new_path', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->required()
                ->description('Must exist in pub/media.'))
            ->string('scope', fn (StringBuilder $s) => $s
                ->enum(['cms_page', 'cms_block', 'menu', 'global'])
                ->required())
            ->array('entity_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers()
                ->description('Limit to these ids of the scoped type.'))
            ->string('alt', fn (StringBuilder $s) => $s->description('Alt text for the replaced image fields.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Save the drafts. Without it the call is a dry run that writes nothing.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $old = $this->editor->normalize($this->requireString($arguments, 'old_path'));
        $new = $this->editor->normalize($this->requireString($arguments, 'new_path'));
        if (!$this->fileInfo->exists($new)) {
            return $this->softError(sprintf('New image "%s" does not exist in pub/media.', $new));
        }
        $scope = $this->requireString($arguments, 'scope');
        $requested = $scope === 'global' ? ContentLocator::TYPES : [$scope];
        $types = $this->contentAcl->allowedTypes($requested, ContentAcl::VERB_SAVE);
        if ($types === []) {
            return $this->softError(sprintf('Your role may not save %s drafts.', implode(' / ', $requested)));
        }
        $targets = $this->editor->findTargets($old, $types, $this->intListArg($arguments, 'entity_ids'));
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
        // T-168: without confirm nothing is written for any scope. A global call previews even with no editable
        // target (to show the usages it cannot change); a one-type call with none says so at once.
        $confirmed = $this->boolArg($arguments, 'confirm');
        if ($scope === 'global' && !$confirmed) {
            return $this->preview('replace_image_globally', $targets, $outOfScope);
        }
        if ($targets['editable'] === []) {
            return $this->softError(
                isset($outOfScope['skipped_out_of_scope'])
                    ? sprintf('"%s" is used only in %s Hyvä content outside your role\'s store views.', $old, $scope)
                    : sprintf('"%s" is not used in any %s Hyvä content.', $old, $scope),
                ['not_editable_usages' => $targets['not_editable']] + $outOfScope
            );
        }
        if (!$confirmed) {
            return $this->preview('replace_image', $targets, $outOfScope);
        }
        $results = $this->editor->rewrite(
            $targets['editable'],
            $this->editor->replaceRewrite($old, $new, $this->stringArg($arguments, 'alt')),
            'Image replaced (MCP)'
        );
        return $this->jsonResult([
            'dry_run' => false,
            'old_path' => $old,
            'new_path' => $new,
            'drafts' => $results,
            'published' => false,
            'not_changed' => $targets['not_editable'],
            'skipped_types_no_permission' => array_values(array_diff($requested, $types)),
        ] + $outOfScope + [
            'next_step' => 'Review the drafts and publish each entity with hyva_cms_publish.',
        ], ['old_path' => $old, 'new_path' => $new, 'entities' => count($results)]);
    }

    /**
     * Dry run: the entities a confirmed call would save a draft of, and the usages it would only report.
     *
     * @param string $action
     * @param array $targets
     * @param array $outOfScope
     * @return ToolResultInterface
     * @phpstan-param array{editable: list<array{entity_type: string, id: int, identifier: string}>,
     *     not_editable: list<ImageUsage>} $targets
     * @phpstan-param array<string, mixed> $outOfScope
     */
    private function preview(string $action, array $targets, array $outOfScope): ToolResultInterface
    {
        $preview = $this->groupByEntity($targets['editable'], $targets['not_editable']);
        return $this->confirmationRequired(
            $action,
            $preview['affected'],
            ['not_editable_usages' => $preview['not_editable']] + $outOfScope,
            null,
            'Confirming saves the replacement as a DRAFT of each affected entity; nothing changes on the '
                . 'storefront until each one is published with hyva_cms_publish. Usages under '
                . 'not_editable_usages (also those of an affected entity, e.g. its Magento CMS content) are '
                . 'reported, not changed.'
        );
    }

    /**
     * Preview rows: each entity once (T-89).
     *
     * A usage that cannot be edited as a draft goes on the affected row of its entity when that entity is also
     * edited (e.g. a page whose Hyvä content and Magento CMS content both use the image), else on its own.
     *
     * @param array $editable
     * @param array $notEditable
     * @return array
     * @phpstan-param list<array{entity_type: string, id: int, identifier: string}> $editable
     * @phpstan-param list<ImageUsage> $notEditable
     * @phpstan-return array{affected: list<array<string, mixed>>, not_editable: list<ImageUsage>}
     */
    private function groupByEntity(array $editable, array $notEditable): array
    {
        $affected = [];
        foreach ($editable as $target) {
            $affected[$target['entity_type'] . ':' . $target['id']] = $target;
        }
        $rest = [];
        foreach ($notEditable as $usage) {
            $key = $usage['entity_type'] . ':' . $usage['id'];
            if (isset($affected[$key])) {
                $usages = $affected[$key]['not_editable_usages'] ?? [];
                $affected[$key]['not_editable_usages'] = [...(is_array($usages) ? $usages : []), $usage];
            } else {
                $rest[] = $usage;
            }
        }
        return ['affected' => array_values($affected), 'not_editable' => $rest];
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
