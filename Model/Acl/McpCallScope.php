<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Acl;

/**
 * Marks the span of an MCP tool's execute(); outside it the MCP caller's role is not handed to Magento authorization.
 */
class McpCallScope
{
    /**
     * @var int
     */
    private int $depth = 0;

    /**
     * Enter.
     *
     * @return void
     */
    public function enter(): void
    {
        $this->depth++;
    }

    /**
     * Leave.
     *
     * @return void
     */
    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /**
     * Is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
