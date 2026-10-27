<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * One email or newsletter template with its Hyvä CMS draft.
 */
abstract class AbstractTemplateGet extends AbstractEmailTool
{
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'One %s: settings, Hyvä CMS state, the draft component outline (uid, component) and draft version '
            . 'uid, and the editor preview link. include_document returns the full draft tree for draft edits.',
            static::LABEL
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withTarget(Schema::object())
            ->boolean('include_document', fn (BooleanBuilder $b) => $b->description('Return the full draft tree.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $template = $this->locateTemplate($arguments);
        $draft = $this->contentService->getWorkingDocument(static::TEMPLATE_TYPE, $template['id']);
        $outline = [];
        foreach (is_array($draft['content'] ?? null) ? $draft['content'] : [] as $root) {
            $this->outline(is_array($root) ? $root : [], 0, $outline);
        }
        $result = [
            'template' => $template,
            'draft_outline' => $outline,
            'draft_version_uid' => is_array($draft['version'] ?? null) ? ($draft['version']['uid'] ?? null) : null,
            'preview' => $this->previewService->getPreview(static::TEMPLATE_TYPE, $template['id']),
        ];
        if ($this->boolArg($arguments, 'include_document')) {
            $result['draft_document'] = $draft;
        }
        return $this->jsonResult($result, ['id' => $template['id']]);
    }

    /**
     * Flat uid / component / depth outline.
     *
     * @param array $node
     * @param int $depth
     * @param array $outline
     * @return void
     * @phpstan-param array<mixed> $node
     * @phpstan-param list<array{uid: string, component: string, depth: int}> $outline
     */
    private function outline(array $node, int $depth, array &$outline): void
    {
        $outline[] = [
            'uid' => Cast::string($node['uid'] ?? ''),
            'component' => Cast::string($node['component'] ?? ''),
            'depth' => $depth,
        ];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            $this->outline(is_array($child) ? $child : [], $depth + 1, $outline);
        }
    }
}
