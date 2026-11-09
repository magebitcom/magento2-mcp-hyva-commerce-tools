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
 * A multi-entity draft rewrite failed after some drafts were saved; the tool answers it softly, naming them.
 *
 * The original failure (a refusal included) is the previous exception.
 */
class PartialRewriteException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param LocalizedException $cause
     * @param array $failedTarget
     * @param array $saved
     * @phpstan-param array{entity_type: string, id: int, identifier: string} $failedTarget
     * @phpstan-param list<array{entity_type: string, id: int, identifier: string, changed: bool,
     *     version_id: int|null}> $saved
     */
    public function __construct(
        Phrase $phrase,
        private readonly LocalizedException $cause,
        private readonly array $failedTarget,
        private readonly array $saved
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * The failure that stopped the rewrite.
     *
     * @return LocalizedException
     */
    public function getCause(): LocalizedException
    {
        return $this->cause;
    }

    /**
     * The entity whose draft was not rewritten.
     *
     * @return array
     * @phpstan-return array{entity_type: string, id: int, identifier: string}
     */
    public function getFailedTarget(): array
    {
        return $this->failedTarget;
    }

    /**
     * Drafts saved before the failure.
     *
     * @return array
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, changed: bool,
     *     version_id: int|null}>
     */
    public function getSaved(): array
    {
        return $this->saved;
    }
}
