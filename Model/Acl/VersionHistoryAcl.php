<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Acl;

use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content\VersionsResolver;

/**
 * Hyvä version_history permission for tools that read a version only for some arguments (a version_id, or the
 * "versions" key of a get tool), so it cannot be part of their static ACL resource.
 *
 * Arguments reach the contextual ACL check unvalidated: they are read here at least as broadly as the tools do.
 */
class VersionHistoryAcl
{
    /**
     * @param ContentAcl $contentAcl
     */
    public function __construct(
        private readonly ContentAcl $contentAcl
    ) {
    }

    /**
     * version_history resource of the type when the call names a version_id, else none.
     *
     * @param string $type
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    public function forVersionId(string $type, array $arguments): array
    {
        return ($arguments['version_id'] ?? null) !== null ? $this->resources($type) : [];
    }

    /**
     * version_history resource of the type when a get call asks for the "versions" key in `fields`, else none.
     *
     * A get without `fields` returns every key; there VersionsResolver leaves versions out for a caller without
     * version history instead of refusing the whole call.
     *
     * @param string $type
     * @param array $arguments
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    public function forFields(string $type, array $arguments): array
    {
        $requested = in_array(VersionsResolver::KEY, $this->stringList($arguments['fields'] ?? null), true)
            && !in_array(VersionsResolver::KEY, $this->stringList($arguments['exclude'] ?? null), true);
        return $requested ? $this->resources($type) : [];
    }

    /**
     * Whether the current caller may see the type's version history.
     *
     * @param string $type
     * @return bool
     */
    public function isAllowed(string $type): bool
    {
        return $this->contentAcl->isAllowed($type, ContentAcl::VERB_VERSION_HISTORY);
    }

    /**
     * version_history resource of the type.
     *
     * @param string $type
     * @return array
     * @phpstan-return list<string>
     */
    private function resources(string $type): array
    {
        return $this->contentAcl->resources([$type], [ContentAcl::VERB_VERSION_HISTORY]);
    }

    /**
     * Strings of a fields / exclude argument; a single string counts as a one-item list, as in ResolverPipeline.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
