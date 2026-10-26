<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Acl;

use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;

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
     * @param ContentTypeAuthorization $contentTypeAuthorization
     */
    public function __construct(
        private readonly ContentTypeAuthorization $contentTypeAuthorization
    ) {
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
