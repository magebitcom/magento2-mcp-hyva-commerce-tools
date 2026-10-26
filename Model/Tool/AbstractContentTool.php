<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;

/**
 * Tool whose Hyvä permission depends on the entity_type argument (Hyvä Commerce 1.4 per-type verbs).
 *
 * tools/list shows it to any role with Hyvä CMS access (Hyva_Cms::cms); before the call the core checks every
 * resource from getContextualAclResources(), e.g. Hyva_Cms::page_publish for publish on a cms_page.
 */
abstract class AbstractContentTool extends AbstractTool implements ContextualAclAwareInterface
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::cms';

    /**
     * Hyvä verbs (ContentAcl::VERB_*) the call needs on each targeted content type.
     */
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * Extra resources the call always needs (e.g. Transfer Center for import / export).
     */
    public const EXTRA_ACL_RESOURCES = [];

    /**
     * Content type a variant of a generic tool is bound to (e.g. "menu" for hyva_menu.publish); '' = from args.
     */
    public const FIXED_CONTENT_TYPE = '';

    /**
     * Content ACL.
     *
     * @return ContentAcl
     */
    abstract protected function contentAcl(): ContentAcl;

    /**
     * Hyvä resource the tool is listed with.
     *
     * A fixed-type variant uses that type's first verb resource from Hyvä (e.g. Hyva_MenuBuilder::menu_publish);
     * when the type's module is off Hyvä answers with its fallback resource, so the tool stays listed and reports
     * the feature as unavailable.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        if (static::FIXED_CONTENT_TYPE === '') {
            return parent::getUnderlyingAclResource();
        }
        return $this->contentAcl()->resources([static::FIXED_CONTENT_TYPE], [static::CONTENT_VERBS[0]])[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        if (static::FIXED_CONTENT_TYPE !== '') {
            $arguments['entity_type'] = static::FIXED_CONTENT_TYPE;
        }
        return parent::execute($arguments);
    }

    /**
     * @inheritDoc
     */
    public function getContextualAclResources(array $arguments): array
    {
        $types = $this->targetContentTypes($arguments);
        if ($types === []) {
            return $this->extraAclResources();
        }
        return array_values(array_unique(array_merge(
            $this->contentAcl()->resources($types, array_values(static::CONTENT_VERBS)),
            $this->extraAclResources()
        )));
    }

    /**
     * Extra resources the call needs (EXTRA_ACL_RESOURCES).
     *
     * A tool whose extra resource belongs to an optional module leaves it out while that module is off: the
     * resource does not exist then, so the core would refuse the call before the tool can answer "not available".
     *
     * @return list<string>
     */
    protected function extraAclResources(): array
    {
        return array_values(static::EXTRA_ACL_RESOURCES);
    }

    /**
     * Content types the call targets; default: the entity_type argument. [] = decided inside the tool.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function targetContentTypes(array $arguments): array
    {
        if (static::FIXED_CONTENT_TYPE !== '') {
            return [static::FIXED_CONTENT_TYPE];
        }
        $type = $arguments['entity_type'] ?? null;
        return is_string($type) && in_array($type, ContentLocator::TYPES, true) ? [$type] : [];
    }

    /**
     * Schema of the generic tool without entity_type, for fixed-type variants.
     *
     * @param array $schema
     * @return array
     * @phpstan-param array<string, mixed> $schema
     * @phpstan-return array<string, mixed>
     */
    protected function withoutEntityType(array $schema): array
    {
        if (is_array($schema['properties'] ?? null)) {
            unset($schema['properties']['entity_type']);
        }
        if (is_array($schema['required'] ?? null)) {
            $schema['required'] = array_values(array_diff($schema['required'], ['entity_type']));
            if ($schema['required'] === []) {
                unset($schema['required']);
            }
        }
        return $schema;
    }
}
