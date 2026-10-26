<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Published (live) document, or null when never published.
 */
class PublishedResolver implements ContentFieldResolverInterface
{
    public const KEY = 'published';

    /**
     * @param ContentService $contentService
     */
    public function __construct(
        private readonly ContentService $contentService
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
        return 50;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $this->contentService->getDocuments($entity['type'], $entity['id'])['published'];
    }
}
