<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Plugin\JsonRpc;

use Magebit\Mcp\Model\Auth\AuthenticatedContext;
use Magebit\Mcp\Model\JsonRpc\HandlerInterface;
use Magebit\Mcp\Model\JsonRpc\Request;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;

/**
 * Binds the MCP caller before the core's tools/list and tools/call handlers check tool ACL resources.
 *
 * Tools listed by ContentAcl::listingResource() resolve their resource from the caller's role; without this the
 * core has no caller at that point and the static fallback lists write tools to read-only roles.
 */
class BindCallerContextPlugin
{
    /**
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * Before handle.
     *
     * @param HandlerInterface $subject
     * @param Request $request
     * @param AuthenticatedContext $context
     * @return void
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function beforeHandle(HandlerInterface $subject, Request $request, AuthenticatedContext $context): void
    {
        $this->callerContext->bind($context);
    }
}
