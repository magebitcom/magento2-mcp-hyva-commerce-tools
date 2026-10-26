<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool;

/**
 * Which `settings` of a page, block or menu update would change the live storefront.
 *
 * Settings are Magento fields, saved at once (no draft). Some always change what the storefront serves or where
 * (is_active, store_ids, identifier); the others (title, meta, layout) show on the storefront when the entity is
 * active. Update tools ask for `confirm: true` before applying any of these.
 */
class LiveSettings
{
    /**
     * Fields that change what the storefront serves, or where, even on an inactive entity.
     */
    public const ALWAYS_LIVE = ['is_active', 'store_ids', 'identifier'];

    /**
     * Live-affecting changes, with what they do to the storefront.
     *
     * @param array $current Current values: identifier, title, is_active, store_ids
     * @param array $settings Requested settings
     * @return array
     * @phpstan-param array<string, mixed> $current
     * @phpstan-param array<array-key, mixed> $settings
     * @phpstan-return list<array{field: string, from: mixed, to: mixed, effect: string}>
     */
    public function changes(array $current, array $settings): array
    {
        $active = !empty($current['is_active']);
        $changes = [];
        foreach ($settings as $field => $value) {
            $field = (string) $field;
            $from = $current[$field] ?? null;
            if ($this->same($from, $value)) {
                continue;
            }
            if (!$active && !in_array($field, self::ALWAYS_LIVE, true)) {
                continue;
            }
            $changes[] = ['field' => $field, 'from' => $from, 'to' => $value, 'effect' => $this->effect($field, $value)];
        }
        return $changes;
    }

    /**
     * Storefront effect of one change.
     *
     * @param string $field
     * @param mixed $value
     * @return string
     */
    private function effect(string $field, mixed $value): string
    {
        return match ($field) {
            'is_active' => $value ? 'Goes live on the storefront.' : 'Goes offline on the storefront.',
            'identifier' => 'Changes the URL key / identifier; links and placements using the old one stop working.',
            'store_ids' => 'Changes the store views it is shown on.',
            default => 'Shows on the storefront at once (the entity is active).',
        };
    }

    /**
     * Same value, ignoring scalar type and list order (store ids).
     *
     * @param mixed $from
     * @param mixed $to
     * @return bool
     */
    private function same(mixed $from, mixed $to): bool
    {
        if (is_array($from) && is_array($to)) {
            $a = array_map('strval', array_filter($from, 'is_scalar'));
            $b = array_map('strval', array_filter($to, 'is_scalar'));
            sort($a);
            sort($b);
            return $a === $b;
        }
        if (is_bool($from) || is_bool($to)) {
            return (bool) $from === (bool) $to;
        }
        return is_scalar($from) && is_scalar($to) && (string) $from === (string) $to;
    }
}
