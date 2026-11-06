<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Magebit\Mcp\Exception\ForbiddenException;

/**
 * Asserts that a tool call is refused as a whole: core's ForbiddenException (JSON-RPC -32004) with a reason.
 */
trait RefusalAssertTrait
{
    /**
     * Run the call and return its refusal; fails when the call returns or throws anything else.
     *
     * @param callable $call
     * @param string $reason "scope" or "acl"
     * @return ForbiddenException
     */
    private function assertRefused(callable $call, string $reason): ForbiddenException
    {
        try {
            $call();
        } catch (ForbiddenException $e) {
            $this->assertSame($reason, $e->getData()['reason'] ?? null, $e->getMessage());
            return $e;
        }
        $this->fail('The call was not refused with ForbiddenException.');
    }

    /**
     * The refusal as a soft reply used to read: its message under "error" plus its error.data.
     *
     * @param callable $call
     * @param string $reason
     * @return array<string, mixed>
     */
    private function refusalPayload(callable $call, string $reason): array
    {
        $refusal = $this->assertRefused($call, $reason);
        return ['error' => $refusal->getMessage()] + $refusal->getData();
    }
}
