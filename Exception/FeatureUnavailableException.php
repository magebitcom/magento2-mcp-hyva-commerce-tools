<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Thrown deep in a call when an optional Hyvä feature is missing; tools turn it into the soft
 * {available: false, feature, reason, hint} result.
 */
class FeatureUnavailableException extends LocalizedException
{
    /**
     * @param array $payload
     * @phpstan-param array{available: false, feature: string, reason: string, hint: string} $payload
     */
    public function __construct(
        private readonly array $payload
    ) {
        parent::__construct(new Phrase($payload['reason']));
    }

    /**
     * Soft-error body for the tool result.
     *
     * @return array
     * @phpstan-return array{available: false, feature: string, reason: string, hint: string}
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
