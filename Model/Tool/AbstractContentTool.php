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
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Tool whose Hyvä permission depends on the entity_type argument (Hyvä Commerce 1.4 per-type verbs).
 *
 * tools/list shows it to a role that holds its verbs on at least one content type (ContentAcl::listingResource();
 * the caller is bound for it by Plugin\JsonRpc\BindCallerContextPlugin); before the call the core checks every
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
     * Content types a generic tool can work on; tools/list counts only these (e.g. migration: pages and blocks).
     */
    public const LISTING_CONTENT_TYPES = ContentLocator::TYPES;

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
     * A generic tool is listed with a resource the caller holds when its CONTENT_VERBS (and extra resources) are
     * allowed on at least one content type, so a read-only role does not see a write tool it cannot use on any type.
     * A fixed-type variant uses that type's first verb resource from Hyvä (e.g. Hyva_MenuBuilder::menu_publish);
     * while the type's module is off Hyvä has no mapping for it and the variant is hidden from tools/list.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        if (static::FIXED_CONTENT_TYPE === '') {
            return $this->contentAcl()->listingResource(
                static::LISTING_CONTENT_TYPES,
                $this->contentVerbs([]),
                $this->extraAclResources(),
                static::UNDERLYING_ACL_RESOURCE
            );
        }
        if (!$this->contentAcl()->isMapped(static::FIXED_CONTENT_TYPE)) {
            return ContentAcl::UNAVAILABLE_RESOURCE;
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
            $this->contentAcl()->resources($types, $this->contentVerbs($arguments)),
            $this->extraAclResources()
        )));
    }

    /**
     * Hyvä verbs the call needs on each targeted type; default CONTENT_VERBS.
     *
     * A tool adds a verb for an argument that reads more, e.g. version_history when it reads a version. Arguments are
     * not validated yet here: a tool reads them at least as broadly as run() does.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function contentVerbs(array $arguments): array
    {
        return array_values(static::CONTENT_VERBS);
    }

    /**
     * View on menus when the call targets a menu (T-145): menus stay out of reach of a role without menu_view.
     *
     * For tools whose verbs do not include view (e.g. scheduling); Hyvä keeps menu_view and menu_scheduling as
     * siblings, so a role can hold one without the other.
     *
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function menuViewVerbs(array $arguments): array
    {
        return in_array(ContentLocator::TYPE_MENU, $this->targetContentTypes($arguments), true)
            ? [ContentAcl::VERB_VIEW]
            : [];
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
            $schema['required'] = array_values(array_diff(Cast::stringList($schema['required']), ['entity_type']));
            if ($schema['required'] === []) {
                unset($schema['required']);
            }
        }
        return $schema;
    }
}
