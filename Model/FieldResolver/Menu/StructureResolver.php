<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Readable draft structure: root components with nested items (uid, label, link, children).
 */
class StructureResolver implements ContentFieldResolverInterface
{
    public const KEY = 'structure';

    /**
     * @param MenuService $menuService
     */
    public function __construct(
        private readonly MenuService $menuService
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
        $edition = is_string($args['edition'] ?? null) ? $args['edition'] : 'draft';
        $document = $this->menuService->getDocument($entity['id'], $edition);
        return $document === null ? [] : $this->menuService->describe($document);
    }
}
