<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image;

use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\ImageUsageIndex;

/**
 * Every place the image is referenced, and alt-text state per usage.
 */
class UsageResolver implements ImageFieldResolverInterface
{
    public const KEY = 'usage';

    /**
     * @param ImageUsageIndex $usageIndex
     */
    public function __construct(
        private readonly ImageUsageIndex $usageIndex
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 30;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, array $args): mixed
    {
        unset($args);
        $usages = $this->usageIndex->usagesOf($path);
        return [
            'total' => count($usages),
            'missing_alt' => count(array_filter($usages, static fn (array $u): bool => !$u['has_alt'])),
            'usages' => $usages,
        ];
    }
}
