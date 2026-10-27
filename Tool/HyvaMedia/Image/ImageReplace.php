<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_media.image.replace` MCP tool.
 */
class ImageReplace extends AbstractMediaTool implements ContextualAclAwareInterface
{
    public const TOOL_NAME = 'hyva_media.image.replace';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_media_image_replace';
    public const IS_WRITE = true;
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MediaGallery::asset_view';

    /**
     * @param ImageContentEditor $editor
     * @param MediaFileInfo $fileInfo
     * @param ContentAcl $contentAcl
     * @param ContentLocator $contentLocator
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly ImageContentEditor $editor,
        private readonly MediaFileInfo $fileInfo,
        private readonly ContentAcl $contentAcl,
        private readonly ContentLocator $contentLocator,
        private readonly ContentScopeFilter $scopeFilter
    ) {
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
            . '(component structure kept; optional new alt text) and go live only with hyva_cms.publish. Usages in '
            . 'Magento CMS content or product/category descriptions are reported, not changed. scope=global (every '
            . 'Hyvä entity) needs "confirm": true and otherwise returns the usages it would change.';
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
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Required for scope=global.'))
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
        if ($scope === 'global' && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('replace_image_globally', [
                'hyva_entities' => $targets['editable'],
                'not_editable_usages' => $targets['not_editable'],
            ] + $outOfScope);
        }
        if ($targets['editable'] === []) {
            return $this->softError(
                $outOfScope !== []
                    ? sprintf('"%s" is used only in %s Hyvä content outside your role\'s store views.', $old, $scope)
                    : sprintf('"%s" is not used in any %s Hyvä content.', $old, $scope),
                ['not_editable_usages' => $targets['not_editable']] + $outOfScope
            );
        }
        $results = $this->editor->rewrite(
            $targets['editable'],
            $this->editor->replaceRewrite($old, $new, $this->stringArg($arguments, 'alt')),
            'Image replaced (MCP)'
        );
        return $this->jsonResult([
            'old_path' => $old,
            'new_path' => $new,
            'drafts' => $results,
            'published' => false,
            'not_changed' => $targets['not_editable'],
            'skipped_types_no_permission' => array_values(array_diff($requested, $types)),
        ] + $outOfScope + [
            'next_step' => 'Review the drafts and publish each entity with hyva_cms.publish.',
        ], ['old_path' => $old, 'new_path' => $new, 'entities' => count($results)]);
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
