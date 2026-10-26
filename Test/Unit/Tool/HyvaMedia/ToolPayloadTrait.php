<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaMedia;

use Magebit\Mcp\Api\ToolResultInterface;

/**
 * Reads values out of a tool's JSON text result.
 */
trait ToolPayloadTrait
{
    /**
     * Value at a dot path ("affected.assets.0.path") of the result's JSON payload.
     *
     * @param ToolResultInterface $result
     * @param string $path
     * @return mixed
     */
    private function at(ToolResultInterface $result, string $path): mixed
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $value = json_decode(is_string($text) ? $text : '', true);
        foreach (explode('.', $path) as $key) {
            $this->assertIsArray($value, $path);
            $this->assertArrayHasKey($key, $value, $path);
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * Array at a dot path of the result's JSON payload.
     *
     * @param ToolResultInterface $result
     * @param string $path
     * @return array
     * @phpstan-return array<mixed>
     */
    private function arrayAt(ToolResultInterface $result, string $path): array
    {
        $value = $this->at($result, $path);
        $this->assertIsArray($value, $path);
        return $value;
    }
}
