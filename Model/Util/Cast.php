<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Util;

/**
 * Typed reads of loosely-typed values (DB rows, model data, decoded JSON).
 */
class Cast
{
    /**
     * Int.
     *
     * @param mixed $value
     * @param int $default
     * @return int
     */
    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : (is_bool($value) ? (int) $value : $default);
    }

    /**
     * String.
     *
     * @param mixed $value
     * @param string $default
     * @return string
     */
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Nullable string.
     *
     * @param mixed $value
     * @return string|null
     */
    public static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Bool.
     *
     * @param mixed $value
     * @return bool
     */
    public static function bool(mixed $value): bool
    {
        return is_scalar($value) && (bool) $value;
    }

    /**
     * Array.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return array<mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * String keyed array.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public static function stringKeyed(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $key => $item) {
            $result[(string) $key] = $item;
        }
        return $result;
    }

    /**
     * String list.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<string>
     */
    public static function stringList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $result[] = Cast::string($item);
        }
        return $result;
    }

    /**
     * Int list.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<int>
     */
    public static function intList(mixed $value): array
    {
        $result = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_numeric($item)) {
                $result[] = (int) $item;
            }
        }
        return $result;
    }
}
