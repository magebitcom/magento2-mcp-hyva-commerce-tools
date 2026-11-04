<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Get tools whose `fields` takes group names and, on their own, keys that live inside a group (T-30, T-85, T-99).
 *
 * Such a key comes back at the top level; a name that is neither a group nor such a key is reported in
 * unknown_fields next to available_fields.
 */
trait FlatFieldsTrait
{
    /**
     * Run the field resolvers the `fields` / `exclude` arguments select.
     *
     * @param ResolverPipeline $pipeline
     * @param ContentFieldResolverInterface[] $resolvers
     * @param array $entity
     * @param array $arguments
     * @param list<string> $fields The `fields` argument.
     * @param array $flatKeyToGroup Keys fields also accepts on their own, mapped to the group that holds each.
     * @return array
     * @phpstan-param array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>} $entity
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-param array<string, string> $flatKeyToGroup
     * @phpstan-return array<string, mixed>
     */
    private function resolveFields(
        ResolverPipeline $pipeline,
        array $resolvers,
        array $entity,
        array $arguments,
        array $fields,
        array $flatKeyToGroup
    ): array {
        $groups = [];
        foreach ($resolvers as $resolver) {
            $groups[] = $resolver->getKey();
        }
        $flatKeys = array_keys($flatKeyToGroup);
        $flat = array_values(array_diff(array_intersect($fields, $flatKeys), $groups));
        $unknown = array_values(array_diff($fields, $groups, $flatKeys));
        $flatGroups = [];
        foreach ($flat as $key) {
            $flatGroups[] = $flatKeyToGroup[$key];
        }
        $flatGroups = array_values(array_unique($flatGroups));
        if ($flat !== []) {
            $arguments['fields'] = array_values(array_unique([...array_diff($fields, $flat), ...$flatGroups]));
        }
        $response = [];
        foreach ($pipeline->plan($resolvers, $arguments) as $resolver) {
            if ($resolver instanceof ContentFieldResolverInterface) {
                $response[$resolver->getKey()] = $resolver->resolve($entity, $arguments);
            }
        }
        $resolved = [];
        foreach ($flatGroups as $groupKey) {
            $resolved[$groupKey] = is_array($response[$groupKey] ?? null) ? $response[$groupKey] : [];
            if (!in_array($groupKey, $fields, true)) {
                unset($response[$groupKey]);
            }
        }
        foreach ($flat as $key) {
            $response[$key] = $resolved[$flatKeyToGroup[$key]][$key] ?? null;
        }
        if ($unknown !== []) {
            $response['unknown_fields'] = $unknown;
            $response['available_fields'] = array_values(array_merge($groups, $flatKeys));
        }
        return $response;
    }
}
