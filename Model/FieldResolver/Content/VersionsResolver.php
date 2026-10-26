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
 * The ten most recent version-history items.
 */
class VersionsResolver implements ContentFieldResolverInterface
{
    public const KEY = 'versions';

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
        return 70;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return array_slice($this->contentService->getVersions($entity['type'], $entity['id']), 0, 10);
    }
}
