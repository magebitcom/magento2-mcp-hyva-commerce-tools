<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Acl;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;

/**
 * Hyvä Commerce 1.4 permissions per (content type, verb), resolved by Hyvä's own ContentTypeAuthorization.
 *
 * Verbs are Hyvä's (view, save, publish, delete, export, version_delete, version_organise, schedule_save,
 * schedule_cancel) plus the group resources version_history and scheduling, which Hyvä declares per type.
 * isAllowed() checks the current MCP caller: McpRoleLocatorPlugin gives Magento authorization the caller's role.
 */
class ContentAcl
{
    public const VERB_VIEW = ContentTypeAuthorization::VERB_VIEW;
    public const VERB_SAVE = ContentTypeAuthorization::VERB_SAVE;
    public const VERB_PUBLISH = ContentTypeAuthorization::VERB_PUBLISH;
    public const VERB_DELETE = ContentTypeAuthorization::VERB_DELETE;
    public const VERB_EXPORT = ContentTypeAuthorization::VERB_EXPORT;
    public const VERB_SCHEDULE_SAVE = ContentTypeAuthorization::VERB_SCHEDULE_SAVE;
    public const VERB_SCHEDULE_CANCEL = ContentTypeAuthorization::VERB_SCHEDULE_CANCEL;
    public const VERB_VERSION_HISTORY = 'version_history';
    public const VERB_SCHEDULING = 'scheduling';
    public const TRANSFER_RESOURCE = 'Hyva_Cms::transfer';

    /**
     * Resource that is never declared, so no role holds it: a tool listed with it is hidden from tools/list.
     *
     * Used while the optional Hyvä module a tool needs (e.g. Menu Builder) is missing or disabled.
     */
    public const UNAVAILABLE_RESOURCE = 'Magebit_McpHyvaCommerceTools::feature_unavailable';

    /**
     * @param ContentTypeAuthorization $contentTypeAuthorization
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly ContentTypeAuthorization $contentTypeAuthorization,
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * Resource a tool with per-type verbs is listed with, so tools/list shows it only to a role that can use it.
     *
     * The core takes one resource per tool and has no arguments in tools/list, so this names a resource the MCP
     * caller holds when every resource in $required and every verb on at least one installed type is allowed,
     * otherwise one the caller lacks (the tool is hidden). Outside an MCP request (CLI) it returns $fallback.
     *
     * @param array $types Content types the tool accepts; types Hyvä has no mapping for are skipped
     * @param array $verbs Verbs every call needs on its type
     * @param array $required Resources every call needs whatever the type
     * @param string $fallback
     * @return string
     * @phpstan-param list<string> $types
     * @phpstan-param list<string> $verbs
     * @phpstan-param list<string> $required
     */
    public function listingResource(array $types, array $verbs, array $required, string $fallback): string
    {
        foreach ($required as $resource) {
            $allowed = $this->callerContext->isAllowed($resource);
            if ($allowed !== true) {
                return $allowed === null ? $fallback : $resource;
            }
        }
        $denied = null;
        foreach (array_intersect($types, $this->getContentTypes()) as $type) {
            $missing = null;
            foreach ($this->resources([$type], $verbs) as $resource) {
                $allowed = $this->callerContext->isAllowed($resource);
                if ($allowed === null) {
                    return $fallback;
                }
                if (!$allowed) {
                    $missing = $resource;
                    break;
                }
            }
            if ($missing === null) {
                return $this->resources([$type], $verbs)[0] ?? $fallback;
            }
            $denied ??= $missing;
        }
        return $denied ?? self::UNAVAILABLE_RESOURCE;
    }

    /**
     * Whether Hyvä maps the content type to its own resources (false while its module, e.g. Menu Builder, is off).
     *
     * @param string $type
     * @return bool
     */
    public function isMapped(string $type): bool
    {
        return $this->contentTypeAuthorization->isMapped($type);
    }

    /**
     * ACL resources for every type x verb pair.
     *
     * @param array $types
     * @param array $verbs
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-param list<string> $verbs
     * @phpstan-return list<string>
     */
    public function resources(array $types, array $verbs): array
    {
        $resources = [];
        foreach ($types as $type) {
            foreach ($verbs as $verb) {
                $resources[] = $this->contentTypeAuthorization->getResource($type, $verb);
            }
        }
        return array_values(array_unique($resources));
    }

    /**
     * Whether the current caller may use the verb on the content type.
     *
     * @param string $type
     * @param string $verb
     * @return bool
     */
    public function isAllowed(string $type, string $verb): bool
    {
        return $this->contentTypeAuthorization->isAllowed($type, $verb);
    }

    /**
     * Content types from the list the current caller may use the verb on.
     *
     * @param array $types
     * @param string $verb
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-return list<string>
     */
    public function allowedTypes(array $types, string $verb): array
    {
        return array_values(array_filter($types, fn (string $type): bool => $this->isAllowed($type, $verb)));
    }

    /**
     * Allowed verbs per content type (for hyva_cms.permission.check, added in the Wave 2 commit).
     *
     * @param string $type
     * @return array
     * @phpstan-return array<string, bool>
     */
    public function allowedVerbs(string $type): array
    {
        $verbs = $this->contentTypeAuthorization->getAllowedVerbs($type);
        foreach ([self::VERB_VERSION_HISTORY, self::VERB_SCHEDULING] as $group) {
            if ($this->contentTypeAuthorization->declaresVerb($type, $group)) {
                $verbs[$group] = $this->contentTypeAuthorization->isAllowed($type, $group);
            }
        }
        return $verbs;
    }

    /**
     * Content types Hyvä has registered a permission prefix for.
     *
     * @return array
     * @phpstan-return list<string>
     */
    public function getContentTypes(): array
    {
        return array_values($this->contentTypeAuthorization->getContentTypes());
    }

    /**
     * Readable label of a content type, e.g. "page".
     *
     * @param string $type
     * @return string
     */
    public function getLabel(string $type): string
    {
        return $this->contentTypeAuthorization->getLabel($type);
    }
}
