<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\ImageReferenceExtractor;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Images referenced by the working draft, with alt-text state.
 */
class ImagesResolver implements ContentFieldResolverInterface
{
    public const KEY = 'images';

    /**
     * @param ContentService $contentService
     * @param ImageReferenceExtractor $imageReferenceExtractor
     */
    public function __construct(
        private readonly ContentService $contentService,
        private readonly ImageReferenceExtractor $imageReferenceExtractor
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
        return 80;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $this->imageReferenceExtractor->fromDocument(
            $this->contentService->getWorkingDocument($entity['type'], $entity['id'])
        );
    }
}
