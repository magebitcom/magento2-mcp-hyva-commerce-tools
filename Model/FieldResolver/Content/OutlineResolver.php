<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Flat outline of the working draft: uid, component, depth, parent and a short label.
 */
class OutlineResolver implements ContentFieldResolverInterface
{
    public const KEY = 'outline';

    /**
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     */
    public function __construct(
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree
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
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        $outline = [];
        $document = $this->contentService->getWorkingDocument($entity['type'], $entity['id']);
        foreach ($this->componentTree->flatten($document) as $row) {
            $node = $row['node'];
            $label = null;
            foreach (['text', 'title', 'label', 'heading', 'user_defined_name'] as $field) {
                if (is_string($node[$field] ?? null) && trim(strip_tags($node[$field])) !== '') {
                    $label = mb_substr(trim(strip_tags($node[$field])), 0, 80);
                    break;
                }
            }
            $outline[] = [
                'uid' => $node['uid'] ?? null,
                'component' => $node['component'] ?? null,
                'depth' => $row['depth'],
                'parent_uid' => $row['parent_uid'],
                'label' => $label,
            ];
        }
        return $outline;
    }
}
