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

/**
 * Where the menu is used (design config, CMS content, widgets, layout/templates).
 */
class MenuUsageResolver implements ContentFieldResolverInterface
{
    public const KEY = 'usage';

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
        return 90;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $this->menuService->findUsage($entity['identifier']);
    }
}
