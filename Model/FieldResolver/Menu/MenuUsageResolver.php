<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;

/**
 * Where the menu is used (design config, CMS content, widgets, layout/templates); content and widget instances
 * outside the caller's websites/store views are left out and counted.
 */
class MenuUsageResolver implements ContentFieldResolverInterface
{
    public const KEY = 'usage';

    /**
     * @param MenuService $menuService
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly MenuService $menuService,
        private readonly ContentScopeFilter $scopeFilter
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
        return $this->scopeFilter->filterUsageReport(
            $this->menuService->findUsage($entity['identifier']),
            ['hyva_cms_content', 'magento_cms_content'],
            ['widget_instances']
        );
    }
}
