<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Image;

use Magebit\McpHyvaCommerceTools\Api\ImageFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Media\MediaFileInfo;

/**
 * File facts: existence, size, dimensions, MIME type, modification time.
 */
class FileResolver implements ImageFieldResolverInterface
{
    public const KEY = 'file';

    /**
     * @param MediaFileInfo $fileInfo
     */
    public function __construct(
        private readonly MediaFileInfo $fileInfo
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
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, array $args): mixed
    {
        unset($args);
        return $this->fileInfo->describe($path);
    }
}
