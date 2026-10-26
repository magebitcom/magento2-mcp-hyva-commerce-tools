<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Entity identity and Magento state.
 */
class IdentityResolver implements ContentFieldResolverInterface
{
    public const KEY = 'identity';

    /**
     * @param PreviewService $previewService
     */
    public function __construct(
        private readonly PreviewService $previewService
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
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $entity + ['published_urls' => $this->previewService->getPublishedUrls($entity['type'], $entity['id'])];
    }
}
