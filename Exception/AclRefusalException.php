<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Exception;

use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;

/**
 * Refusal for a missing admin permission that names it; the tool boundary answers it with JSON-RPC -32004,
 * `error.data.reason` "acl" and `error.data.resource` (or `resources` when several are missing).
 */
class AclRefusalException extends AuthorizationException
{
    /**
     * @param Phrase $phrase
     * @param string|list<string> $resource The missing ACL resource, or every one of them
     */
    public function __construct(
        Phrase $phrase,
        private readonly string|array $resource
    ) {
        parent::__construct($phrase);
    }

    /**
     * Extra keys for the JSON-RPC `error.data` member.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if (is_string($this->resource)) {
            return ['resource' => $this->resource];
        }
        return count($this->resource) === 1 ? ['resource' => $this->resource[0]] : ['resources' => $this->resource];
    }
}
