<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Permission;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

/**
 * `hyva_cms.permission.check`: what the caller may do per Hyvä content type (Hyvä Commerce 1.4 ACL).
 */
class PermissionCheck extends AbstractTool
{
    public const TOOL_NAME = 'hyva_cms.permission.check';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_permission_check';
    public const UNDERLYING_ACL_RESOURCE = null;

    /**
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Check Hyvä CMS Permissions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Which Hyvä Commerce actions your admin role allows, per content type (pages, blocks, menus, forms, '
            . 'email and newsletter templates, templates, snippets, attributes, instance components): view, save '
            . '(draft), publish, delete, export, version history, scheduling. Ask this before planning work, so '
            . 'you do not prepare changes you cannot save or publish.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('entity_type', fn (StringBuilder $s) => $s->description('One content type; default: all.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $types = $this->contentAcl->getContentTypes();
        $only = $this->stringArg($arguments, 'entity_type');
        if ($only !== null) {
            if (!in_array($only, $types, true)) {
                return $this->softError(sprintf('Unknown content type "%s". Known: %s.', $only, implode(', ', $types)));
            }
            $types = [$only];
        }
        $result = [];
        foreach ($types as $type) {
            $verbs = $this->contentAcl->allowedVerbs($type);
            $result[$type] = [
                'label' => $this->contentAcl->getLabel($type),
                'allowed' => array_keys(array_filter($verbs)),
                'denied' => array_keys(array_filter($verbs, static fn (bool $allowed): bool => !$allowed)),
            ];
        }
        return $this->jsonResult(['content_types' => $result], ['types' => count($result)]);
    }
}
