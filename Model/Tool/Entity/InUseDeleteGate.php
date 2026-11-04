<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magento\Framework\Exception\LocalizedException;

/**
 * Delete tools: an entity still in use is deleted only with "force": true next to "confirm": true (T-73), and the
 * result of such a delete says so ("forced": true) and lists the usages that broke (T-79).
 *
 * Used by AbstractEntityDelete (CMS pages and blocks), MenuDelete and AbstractTemplateDelete (email and newsletter
 * templates), which all extend AbstractTool.
 */
trait InUseDeleteGate
{
    /**
     * Input schema of a delete tool: the entity target, confirm and force
     *
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function deleteInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Delete, after reviewing the preview. Without it nothing is deleted.'
            ))
            ->boolean('force', fn (BooleanBuilder $b) => $b->description(
                'Also delete when the preview says it is still in use (in_use: true); needs "confirm": true as '
                    . 'well. Default false: an entity in use is refused. Not needed when it is not in use.'
            ))
            ->toArray();
    }

    /**
     * Preview hint addition for an entity in use
     *
     * @param string $consequence What happens to the usages, e.g. "those references break."
     * @return string
     */
    private function inUseHint(string $consequence): string
    {
        return ' It is still in use (details.usage): ' . $consequence . ' Deleting it needs "force": true as well '
            . 'as "confirm": true; tell the user where it is used before confirming.';
    }

    /**
     * Soft error (status 409) for a confirmed delete of an entity in use without "force": true
     *
     * @param string $label E.g. CMS block "footer" (#5).
     * @param array $usage The DeletionUsage report shown in the preview.
     * @param string $consequence What happens to the usages, e.g. "those references break."
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $usage
     */
    private function inUseRefusal(string $label, array $usage, string $consequence): ToolResultInterface
    {
        return $this->softError(
            sprintf(
                '%s is still in use (%s). Nothing was deleted. To delete it anyway, call again with '
                    . '"confirm": true and "force": true; %s',
                $label,
                $this->usagePlaces($usage),
                $consequence
            ),
            ['status' => 409, 'in_use' => true, 'force_required' => true, 'usage' => $usage],
            'in_use'
        );
    }

    /**
     * Fields a confirmed delete adds to its result when it removed an entity still in use ("force": true)
     *
     * The usages are the DeletionUsage report of the preview, so the caller sees which references broke.
     *
     * @param array $usage
     * @param string $aftermath What the caller should know or do now, after the usage list.
     * @return array
     * @phpstan-param array<string, mixed> $usage
     * @phpstan-return array{forced: true, usage: array<string, mixed>, hint: string}
     */
    private function forcedDeleteDetails(
        array $usage,
        string $aftermath = 'Those references are now broken: remove or replace them.'
    ): array {
        return [
            'forced' => true,
            'usage' => $usage,
            'hint' => sprintf(
                'Deleted with "force": true while it was still in use (%s). %s',
                $this->usagePlaces($usage),
                $aftermath
            ),
        ];
    }

    /**
     * Where an entity is used, as "<usage list> <count>" per non-empty list of the report, then "details in usage"
     *
     * Usages the caller's role may not see are named only by their count.
     *
     * @param array $usage
     * @return string
     * @phpstan-param array<string, mixed> $usage
     */
    private function usagePlaces(array $usage): string
    {
        $places = [];
        foreach ($usage as $key => $value) {
            if (is_array($value) && $value !== [] && array_is_list($value)) {
                $places[] = $key . ' ' . count($value);
            }
        }
        $hidden = $usage['usages_hidden_by_permissions'] ?? 0;
        if (is_int($hidden) && $hidden > 0) {
            $places[] = $hidden . ' outside your role';
        }
        $places[] = 'details in usage';
        return implode(', ', $places);
    }
}
