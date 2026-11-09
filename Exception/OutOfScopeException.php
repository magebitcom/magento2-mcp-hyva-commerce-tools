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
 * Refusal by the caller's website / store view restriction (Hyvä Advanced Admin Permissions), as opposed to a missing
 * permission; the tool boundary answers it with JSON-RPC -32004 and `error.data.reason` "scope".
 */
class OutOfScopeException extends AuthorizationException
{
    /**
     * @param Phrase $phrase
     * @param array $data Extra `error.data` keys, such as the refused store view ids
     * @phpstan-param array<string, mixed> $data
     */
    public function __construct(
        Phrase $phrase,
        private readonly array $data = []
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
        return $this->data;
    }
}
