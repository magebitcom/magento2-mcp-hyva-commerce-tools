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
 * Get tools whose `fields` takes group names and, on their own, the keys of one group (T-30, T-85).
 *
 * A key of that group comes back at the top level; a name that is neither a group nor such a key is reported in
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
     * @param string $groupKey Group whose keys fields also accepts on their own.
     * @param list<string> $flatKeys Those keys.
     * @return array
     * @phpstan-param array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>} $entity
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    private function resolveFields(
        ResolverPipeline $pipeline,
        array $resolvers,
        array $entity,
        array $arguments,
        array $fields,
        string $groupKey,
        array $flatKeys
    ): array {
        $groups = [];
        foreach ($resolvers as $resolver) {
            $groups[] = $resolver->getKey();
        }
        $flat = array_values(array_diff(array_intersect($fields, $flatKeys), $groups));
        $unknown = array_values(array_diff($fields, $groups, $flatKeys));
        if ($flat !== []) {
            $arguments['fields'] = array_values(array_unique([...array_diff($fields, $flat), $groupKey]));
        }
        $response = [];
        foreach ($pipeline->plan($resolvers, $arguments) as $resolver) {
            if ($resolver instanceof ContentFieldResolverInterface) {
                $response[$resolver->getKey()] = $resolver->resolve($entity, $arguments);
            }
        }
        if ($flat !== []) {
            $group = is_array($response[$groupKey] ?? null) ? $response[$groupKey] : [];
            if (!in_array($groupKey, $fields, true)) {
                unset($response[$groupKey]);
            }
            foreach ($flat as $key) {
                $response[$key] = $group[$key] ?? null;
            }
        }
        if ($unknown !== []) {
            $response['unknown_fields'] = $unknown;
            $response['available_fields'] = array_values(array_merge($groups, $flatKeys));
        }
        return $response;
    }
}
