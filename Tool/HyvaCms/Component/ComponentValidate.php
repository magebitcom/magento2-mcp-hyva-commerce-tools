<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Component;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.component.validate`: validate component JSON, a document, or a stored draft before saving/publishing.
 */
class ComponentValidate extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.component.validate';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_component_validate';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param ContentLocator $contentLocator
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly ContentLocator $contentLocator,
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Validate Hyvä CMS Components';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Validate before saving or publishing. Pass "components" (raw nodes, as stored), a whole "document", '
            . 'or entity_type + id/identifier to check the stored draft (edition=draft) or live content '
            . '(edition=published). Reports unknown/disabled components, invalid children, missing required fields, '
            . 'invalid select options and Hyvä JSON-schema errors.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->rawProperty(
                'components',
                [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Root component nodes to validate as-is.',
                ]
            )
            ->rawProperty('document', ['type' => 'object', 'description' => 'A full Hyvä CMS content document.'])
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES, false))
            ->string('edition', fn (StringBuilder $s) => $s
                ->enum(['draft', 'published'])
                ->description('Stored content to check; default draft.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->stringArg($arguments, 'entity_type', ContentLocator::TYPE_PAGE) ?? ContentLocator::TYPE_PAGE;
        if (isset($arguments['document']) && is_array($arguments['document'])) {
            $document = $this->componentTree->normalizeDocument($arguments['document'], $type, 0);
            $source = 'document';
        } elseif (isset($arguments['components']) && is_array($arguments['components'])) {
            $document = $this->componentTree->newDocument($type, 0);
            foreach ($arguments['components'] as $node) {
                if (is_array($node)) {
                    $document = $this->componentTree->insertNode($document, $this->stringKeys($node));
                }
            }
            $source = 'components';
        } elseif (isset($arguments['id']) || isset($arguments['identifier'])) {
            $entity = $this->contentLocator->locate($type, $arguments);
            $documents = $this->contentService->getDocuments($entity['type'], $entity['id']);
            $edition = $this->stringArg($arguments, 'edition', 'draft');
            $document = $edition === 'published' ? $documents['published'] : (
                $documents['draft'] ?? $documents['published']
            );
            if ($document === null) {
                return $this->softError(
                    sprintf('%s #%d has no %s content.', $entity['type'], $entity['id'], (string) $edition)
                );
            }
            $source = $entity['type'] . ':' . $entity['id'] . ':' . $edition;
        } else {
            return $this->softError('Pass components, document, or entity_type + id/identifier.');
        }

        $validation = $this->contentService->validate($document);
        return $this->jsonResult(['source' => $source] + $validation + [
            'components' => $this->componentTree->countComponents($document)
        ], [
            'source' => $source,
            'valid' => $validation['valid'],
            'issues' => count($validation['issues']),
        ]);
    }

    /**
     * String keys.
     *
     * @param array $data
     * @return array
     * @phpstan-param array<mixed> $data
     * @phpstan-return array<string, mixed>
     */
    private function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }

    /**
     * Content types the call targets.
     *
     * Without entity_type, a stored entity (id / identifier) is read as a page, so the page view verb applies.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function targetContentTypes(array $arguments): array
    {
        $types = parent::targetContentTypes($arguments);
        if ($types === [] && (isset($arguments['id']) || isset($arguments['identifier']))) {
            return [ContentLocator::TYPE_PAGE];
        }
        return $types;
    }
}
