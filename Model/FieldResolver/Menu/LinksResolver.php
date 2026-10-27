<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Every link in the draft with its target resolved (category / product / CMS page names and state).
 */
class LinksResolver implements ContentFieldResolverInterface
{
    public const KEY = 'links';

    /**
     * @param MenuService $menuService
     * @param ComponentTree $componentTree
     * @param LinkTargetResolverInterface $linkTargetResolver
     */
    public function __construct(
        private readonly MenuService $menuService,
        private readonly ComponentTree $componentTree,
        private readonly LinkTargetResolverInterface $linkTargetResolver
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
        return 40;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        $edition = is_string($args['edition'] ?? null) ? $args['edition'] : 'draft';
        $document = $this->menuService->getDocument($entity['id'], $edition);
        if ($document === null) {
            return [];
        }
        $links = [];
        $this->componentTree->walk($document, function (array $node) use (&$links): void {
            if (is_array($node['link'] ?? null) && !Cast::bool($node['text_only'] ?? false)) {
                $links[] = [
                    'uid' => $node['uid'] ?? null,
                    'type' => Cast::string($node['link']['type'] ?? ''),
                    'value' => $node['link']['value'] ?? null,
                ];
            }
        });
        $storeId = $this->menuService->lookupStoreId($entity['store_ids']);
        $categories = $this->linkTargetResolver->getCategories(
            array_map(
                static fn (array $l): int => Cast::int($l['value']),
                array_values(array_filter($links, static fn (array $l): bool => $l['type'] === 'category'))
            ),
            $storeId
        );
        $products = $this->linkTargetResolver->getProducts(
            array_map(
                static fn (array $l): string => Cast::string($l['value']),
                array_values(array_filter($links, static fn (array $l): bool => $l['type'] === 'product'))
            ),
            $storeId
        );
        $pages = $this->linkTargetResolver->getCmsPages(
            array_map(
                static fn (array $l): string => Cast::string($l['value']),
                array_values(array_filter($links, static fn (array $l): bool => $l['type'] === 'cms_page'))
            )
        );
        foreach ($links as $index => $link) {
            $links[$index]['target'] = match ($link['type']) {
                'category' => $categories[Cast::int($link['value'])] ?? null,
                'product' => $products[Cast::string($link['value'])] ?? null,
                'cms_page' => $pages[Cast::string($link['value'])] ?? null,
                default => null,
            };
        }
        return $links;
    }
}
