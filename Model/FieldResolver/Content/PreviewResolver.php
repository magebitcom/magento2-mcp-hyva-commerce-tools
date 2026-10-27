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
 * Signed storefront preview URLs per store view and the admin editor URL.
 */
class PreviewResolver implements ContentFieldResolverInterface
{
    public const KEY = 'preview';

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
        return 60;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        $storeId = is_int($args['store_id'] ?? null) ? $args['store_id'] : null;
        return $this->previewService->getPreview($entity['type'], $entity['id'], $storeId);
    }
}
