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
use Magebit\McpHyvaCommerceTools\Model\Media\ImageContentEditor;
use Magebit\McpHyvaCommerceTools\Model\Tool\Media\AbstractMediaTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

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
     * @param ImageContentEditor $editor
     * @param ContentAcl $contentAcl
     * @param ContentLocator $contentLocator
     */
    public function __construct(
        private readonly ImageContentEditor $editor,
        private readonly ContentAcl $contentAcl,
        private readonly ContentLocator $contentLocator
    ) {
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
            . 'and <img> tags in rich text/HTML), saved as DRAFTS. Limit with scope and entity_ids. Never publishes.';
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
            return $this->softError(sprintf('Your role may not save %s drafts.', implode(' / ', $requested)));
        }
        $targets = $this->editor->findTargets($path, $types, $this->intListArg($arguments, 'entity_ids'));
        // A role restricted to some websites or store views only changes the content it may change.
        $inScope = $this->contentLocator->partitionByScope($targets['editable']);
        $targets['editable'] = $inScope['writable'];
        $outOfScope = $inScope['read_only'] !== [] ? ['skipped_out_of_scope' => $inScope['read_only']] : [];
        if ($targets['editable'] === []) {
            return $this->softError(
                sprintf('"%s" is not used in Hyvä content for this scope.', $path),
                ['not_editable_usages' => $targets['not_editable']] + $outOfScope
            );
        }
        $results = $this->editor->rewrite(
            $targets['editable'],
            $this->editor->altRewrite($path, $this->requireString($arguments, 'alt')),
            'Alt text updated (MCP)'
        );
        return $this->jsonResult(
            [
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
