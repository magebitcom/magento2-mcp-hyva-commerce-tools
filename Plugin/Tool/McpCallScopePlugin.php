<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\Tool;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\McpCallScope;

/**
 * Opens the MCP call scope for exactly the duration of a tool's execute(), however it ends.
 */
class McpCallScopePlugin
{
    /**
     * @param McpCallScope $callScope
     */
    public function __construct(
        private readonly McpCallScope $callScope
    ) {
    }

    /**
     * Around execute.
     *
     * @param ToolInterface $subject
     * @param callable $proceed
     * @param array $arguments
     * @return ToolResultInterface
     * @phpstan-param callable(array<string, mixed>): ToolResultInterface $proceed
     * @phpstan-param array<string, mixed> $arguments
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function aroundExecute(ToolInterface $subject, callable $proceed, array $arguments): ToolResultInterface
    {
        $this->callScope->enter();
        try {
            return $proceed($arguments);
        } finally {
            $this->callScope->leave();
        }
    }
}
