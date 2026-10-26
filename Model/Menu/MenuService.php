<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Menu;

use Magebit\Mcp\Api\LoggerInterface;
use FilesystemIterator;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\LinkTargetResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Model\Security\CatalogScopeReader;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Menu-level operations shared by the hyva_menu.* tools, the menu audit and the menu health widget.
 */
class MenuService
{
    /**
     * @var array|null
     * @phpstan-var list<array{0: string, 1: int, 2: string}>|null
     */
    private ?array $codeMenuLines = null;

    public const CONFIG_PATHS = [
        'design/header/topmenu_identifier' => 'header',
        'design/footer/footer_menu_identifier' => 'footer',
    ];

    /**
     * @param MenuGatewayInterface $menuGateway
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param MenuValidator $menuValidator
     * @param MenuItemBuilder $menuItemBuilder
     * @param LinkTargetResolverInterface $linkTargetResolver
     * @param UsageFinder $usageFinder
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param DirectoryList $directoryList
     * @param FileDriver $fileDriver
     * @param LoggerInterface $logger
     * @param StoreScopeGuard $storeScopeGuard
     * @param CatalogScopeReader $catalogScopeReader
     */
    public function __construct(
        private readonly MenuGatewayInterface $menuGateway,
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly MenuValidator $menuValidator,
        private readonly MenuItemBuilder $menuItemBuilder,
        private readonly LinkTargetResolverInterface $linkTargetResolver,
        private readonly UsageFinder $usageFinder,
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly DirectoryList $directoryList,
        private readonly FileDriver $fileDriver,
        private readonly LoggerInterface $logger,
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly CatalogScopeReader $catalogScopeReader
    ) {
    }

    /**
     * Menu the caller may see (website/store view restriction applied).
     *
     * @param array $arguments id | identifier [+ store_id]
     * @return array Menu row from the gateway.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    public function locate(array $arguments): array
    {
        $entity = $this->contentLocator->locate(ContentLocator::TYPE_MENU, $arguments);
        return $this->menuGateway->get($entity['id']);
    }

    /**
     * Menu the caller may change: every store view it is assigned to is within the caller's restriction.
     *
     * @param array $arguments id | identifier [+ store_id]
     * @return array Menu row from the gateway.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<string, mixed>
     */
    public function locateForWrite(array $arguments): array
    {
        $entity = $this->contentLocator->locateForWrite(ContentLocator::TYPE_MENU, $arguments);
        return $this->menuGateway->get($entity['id']);
    }

    /**
     * Get document.
     *
     * @param int $menuId
     * @param string $edition draft (working copy) | published
     * @return array|null
     * @throws LocalizedException
     * @phpstan-return array<string, mixed>|null
     */
    public function getDocument(int $menuId, string $edition = 'draft'): ?array
    {
        if ($edition === 'published') {
            return $this->contentService->getDocuments(ContentLocator::TYPE_MENU, $menuId)['published'];
        }
        return $this->contentService->getWorkingDocument(ContentLocator::TYPE_MENU, $menuId);
    }

    /**
     * Readable structure: one entry per root component with nested items.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array<string, mixed>>
     */
    public function describe(array $document): array
    {
        $roots = [];
        foreach ($this->componentTree->getRootNodes($document) as $root) {
            $roots[] = $this->menuItemBuilder->describe($root);
        }
        return $roots;
    }

    /**
     * Design-config assignments (header/footer) of a menu identifier, per scope.
     *
     * @param string $identifier
     * @return array
     * @phpstan-return list<array{location: string, path: string, scope: string, scope_id: int}>
     */
    public function getAssignments(string $identifier): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('core_config_data'), ['path', 'scope', 'scope_id', 'value'])
            ->where('path IN (?)', array_keys(self::CONFIG_PATHS))
            ->where('value = ?', $identifier);
        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $path = Cast::string($row['path']);
            $rows[] = [
                'location' => self::CONFIG_PATHS[$path] ?? $path,
                'path' => $path,
                'scope' => Cast::string($row['scope']),
                'scope_id' => Cast::int($row['scope_id']),
            ];
        }
        return $rows;
    }

    /**
     * Design-config assignments the caller may see, and how many were left out.
     *
     * Hyvä's ConfigScopeAccess (design configuration grid and edit page): a restricted role never sees the default
     * scope, a website only when it holds every store view of it (a website without store views is refused), a store
     * view scope only for that store view. Unrestricted callers see every assignment.
     *
     * @param array $assignments
     * @return array
     * @phpstan-param list<array{location: string, path: string, scope: string, scope_id: int}> $assignments
     * @phpstan-return array{visible: list<array{location: string, path: string, scope: string, scope_id: int}>,
     *     hidden: int}
     */
    public function filterAssignments(array $assignments): array
    {
        if ($this->storeScopeGuard->restrictedStoreIds() === null) {
            return ['visible' => $assignments, 'hidden' => 0];
        }
        $visible = array_values(array_filter($assignments, function (array $assignment): bool {
            $scopeId = $assignment['scope_id'];
            if ($scopeId === 0) {
                return false;
            }
            if (in_array($assignment['scope'], ['stores', 'store'], true)) {
                return $this->storeScopeGuard->allowsWrite([$scopeId]);
            }
            if (in_array($assignment['scope'], ['websites', 'website'], true)) {
                $storeIds = $this->websiteStoreIds($scopeId);
                return $storeIds !== [] && $this->storeScopeGuard->allowsWrite($storeIds);
            }
            return false;
        }));
        return ['visible' => $visible, 'hidden' => count($assignments) - count($visible)];
    }

    /**
     * A usage report (findUsage(), after ContentScopeFilter::filterUsageReport()) with the design-config assignments
     * the caller may not see taken off `design_config` and `total` and counted in usages_hidden_by_permissions.
     *
     * `used` keeps reporting every usage, so a menu placed in another scope is never mistaken for unused.
     *
     * @param array $report
     * @return array
     * @phpstan-param array<string, mixed> $report
     * @phpstan-return array<string, mixed>
     */
    public function withScopedAssignments(array $report): array
    {
        if ($this->storeScopeGuard->restrictedStoreIds() === null) {
            return $report;
        }
        $assignments = [];
        foreach (is_array($report['design_config'] ?? null) ? $report['design_config'] : [] as $row) {
            if (is_array($row)) {
                $assignments[] = [
                    'location' => Cast::string($row['location'] ?? ''),
                    'path' => Cast::string($row['path'] ?? ''),
                    'scope' => Cast::string($row['scope'] ?? ''),
                    'scope_id' => Cast::int($row['scope_id'] ?? 0),
                ];
            }
        }
        $filtered = $this->filterAssignments($assignments);
        $report['design_config'] = $filtered['visible'];
        if (is_int($report['total'] ?? null)) {
            $report['total'] -= $filtered['hidden'];
        }
        $report['usages_hidden_by_permissions'] = Cast::int($report['usages_hidden_by_permissions'] ?? 0)
            + $filtered['hidden'];
        return $report;
    }

    /**
     * Store view ids of a website; empty for an unknown website or one without store views.
     *
     * @param int $websiteId
     * @return array
     * @phpstan-return list<int>
     */
    private function websiteStoreIds(int $websiteId): array
    {
        $storeIds = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ((int) $store->getWebsiteId() === $websiteId) {
                $storeIds[] = (int) $store->getId();
            }
        }
        return $storeIds;
    }

    /**
     * Every place the menu is referenced.
     *
     * @param string $identifier
     * @return array
     * @phpstan-return array{identifier: string, used: bool, total: int, design_config: list<array{location: string,
     *     path: string, scope: string, scope_id: int}>, hyva_cms_content: list<array<string, mixed>>,
     *     magento_cms_content: list<array<string, mixed>>, widget_instances: list<array<string, mixed>>,
     *     layout_and_templates: list<array{file: string, line: int}>}
     */
    public function findUsage(string $identifier): array
    {
        $references = $this->usageFinder->findMenuReferences($identifier);
        $files = $this->findInCodeFiles($identifier);
        $assignments = $this->getAssignments($identifier);
        $total = count($assignments) + count($references['hyva_content']) + count($references['magento_content'])
            + count($references['widget_instances']) + count($files);
        return [
            'identifier' => $identifier,
            'used' => $total > 0,
            'total' => $total,
            'design_config' => $assignments,
            'hyva_cms_content' => $references['hyva_content'],
            'magento_cms_content' => $references['magento_content'],
            'widget_instances' => $references['widget_instances'],
            'layout_and_templates' => $files,
        ];
    }

    /**
     * Validate.
     *
     * @param array $menu Gateway row.
     * @param string $edition
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $menu
     * @phpstan-return array{issues: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function validate(array $menu, string $edition = 'draft'): array
    {
        $menuId = Cast::int($menu['id'] ?? 0);
        $document = $this->getDocument($menuId, $edition) ?? $this->componentTree->newDocument(
            ContentLocator::TYPE_MENU,
            $menuId
        );
        $storeIds = Cast::intList($menu['store_ids'] ?? []);
        $issues = $this->menuValidator->validate($document, [
            'store_ids' => $storeIds,
            'is_active' => Cast::bool($menu['is_active'] ?? false),
            'is_published' => Cast::bool($menu['is_published'] ?? false),
            'assigned' => $this->getAssignments(Cast::string($menu['identifier'] ?? '')) !== [],
        ], $this->lookupStoreId($storeIds));
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($issues as $issue) {
            $counts[$issue['severity']]++;
        }
        return ['issues' => $issues, 'counts' => $counts];
    }

    /**
     * Plan a category import: what would be created, skipped (already linked) or flagged.
     *
     * @param array $document
     * @param int $rootCategoryId
     * @param int $depth
     * @param int $storeId
     * @param bool $includeInactive
     * @param bool $includeHidden Categories with include_in_menu = No.
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array{tree: list<array<string, mixed>>, create: list<array<string, mixed>>,
     *     skip_existing: list<array<string, mixed>>, inactive: list<array<string, mixed>>, hidden: list<array<string,
     *     mixed>>, missing_url_rewrites: list<array<string, mixed>>, duplicate_labels: list<string>}
     */
    public function planCategoryImport(
        array $document,
        int $rootCategoryId,
        int $depth,
        int $storeId,
        bool $includeInactive,
        bool $includeHidden
    ): array {
        $linked = [];
        $this->componentTree->walk($document, function (array $node) use (&$linked): void {
            $link = is_array($node['link'] ?? null) ? $node['link'] : null;
            if ($link !== null && ($link['type'] ?? null) === 'category') {
                $linked[Cast::int($link['value'] ?? 0)][] = is_string($node['uid'] ?? null) ? $node['uid'] : '';
            }
        });

        $plan = [
            'tree' => [],
            'create' => [],
            'skip_existing' => [],
            'inactive' => [],
            'hidden' => [],
            'missing_url_rewrites' => [],
            'duplicate_labels' => [],
        ];
        $names = [];
        $plan['tree'] = $this->filterTree(
            $this->categoryTreeWithinCallerScope($rootCategoryId, $depth, $storeId),
            $linked,
            $includeInactive,
            $includeHidden,
            $plan,
            $names
        );
        foreach (array_count_values($names) as $name => $count) {
            if ($count > 1) {
                $plan['duplicate_labels'][] = (string) $name;
            }
        }
        return $plan;
    }

    /**
     * Category subtree under a root the caller may see.
     *
     * Like Hyvä's CatalogCategoryEntityGuard: a restricted role may only read categories under the root category of
     * an allowed store view; under the tree root ("1") only the store roots it holds are kept.
     *
     * @param int $rootCategoryId
     * @param int $depth
     * @param int $storeId
     * @return array
     * @throws AuthorizationException When the root category is outside the caller's scope.
     * @phpstan-return list<array<string, mixed>>
     */
    private function categoryTreeWithinCallerScope(int $rootCategoryId, int $depth, int $storeId): array
    {
        $path = $this->catalogScopeReader->categoryPaths([$rootCategoryId])[$rootCategoryId] ?? null;
        if ($path !== null && !$this->storeScopeGuard->allowsCategoryPath($path)) {
            throw new AuthorizationException(__(
                'Your admin role is restricted to other websites or store views; category #%1 is outside it.',
                $rootCategoryId
            ));
        }
        $tree = $this->linkTargetResolver->getCategoryTree($rootCategoryId, $depth, $storeId);
        if ($path === null || str_contains($path, '/')) {
            return $tree;
        }
        return array_values(array_filter(
            $tree,
            fn (array $root): bool => $this->storeScopeGuard->allowsCategoryPath($path . '/' . $root['id'])
        ));
    }

    /**
     * Item specs (for MenuItemBuilder) from a planned category tree.
     *
     * @param array $tree
     * @param string $mode nested|flat
     * @return array
     * @phpstan-param list<array<string, mixed>> $tree
     * @phpstan-return list<array<string, mixed>>
     */
    public function treeToItemSpecs(array $tree, string $mode): array
    {
        $specs = [];
        foreach ($tree as $category) {
            $children = is_array($category['children'] ?? null) ? $category['children'] : [];
            $spec = [
                'type' => 'category',
                'value' => Cast::int($category['id'] ?? 0),
                'label' => Cast::string($category['name'] ?? ''),
                'placeholder' => Cast::string($category['name'] ?? ''),
            ];
            if ($mode === 'flat') {
                $specs[] = $spec;
                foreach ($this->treeToItemSpecs($this->listOfArrays($children), 'flat') as $flatChild) {
                    $specs[] = $flatChild;
                }
                continue;
            }
            $spec['children'] = $this->treeToItemSpecs($this->listOfArrays($children), 'nested');
            $specs[] = $spec;
        }
        return $specs;
    }

    /**
     * Where each imported item goes.
     *
     * New categories go under the target parents; in nested mode, new subcategories of a category that is already
     * in the menu go under that existing item (inside the targeted roots only). New subcategories of an item outside
     * the targeted roots are not placed; they are appended to $skipped as id + name.
     *
     * @param array $document
     * @param array $tree Planned tree from planCategoryImport().
     * @param array $parents Target parents.
     * @param string $mode nested|flat
     * @param array $skipped Receives the new categories left out because their parent item is outside the targets.
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param list<array<string, mixed>> $tree
     * @phpstan-param list<array{uid: string, component: string}> $parents
     * @phpstan-param list<array{id: int, name: string}> $skipped
     * @phpstan-return list<array{parent_uid: string, parent_component: string, spec: array<string, mixed>}>
     */
    public function placements(
        array $document,
        array $tree,
        array $parents,
        string $mode,
        array &$skipped = []
    ): array {
        $placements = [];
        if ($mode === 'flat') {
            foreach ($this->treeToItemSpecs($this->flattenNew($tree), 'flat') as $spec) {
                foreach ($parents as $parent) {
                    $placements[] = [
                        'parent_uid' => $parent['uid'],
                        'parent_component' => $parent['component'],
                        'spec' => $spec,
                    ];
                }
            }
            return $placements;
        }
        $parentUids = array_column($parents, 'uid');
        foreach ($tree as $node) {
            $children = $this->listOfArrays($node['children'] ?? []);
            if (empty($node['already_linked'])) {
                foreach ($this->treeToItemSpecs([$node], 'nested') as $spec) {
                    foreach ($parents as $parent) {
                        $placements[] = [
                            'parent_uid' => $parent['uid'],
                            'parent_component' => $parent['component'],
                            'spec' => $spec,
                        ];
                    }
                }
                continue;
            }
            foreach (Cast::array($node['existing_uids'] ?? []) as $existingUid) {
                $existingUid = Cast::string($existingUid);
                $existing = $this->componentTree->findNode($document, $existingUid);
                if ($existing === null) {
                    continue;
                }
                if (!$this->isWithin($document, $existingUid, $parentUids)) {
                    foreach ($this->newCategories($children) as $category) {
                        $skipped[] = $category;
                    }
                    continue;
                }
                $target = [['uid' => $existingUid, 'component' => Cast::string($existing['component'] ?? '')]];
                foreach ($this->placements($document, $children, $target, 'nested', $skipped) as $placement) {
                    $placements[] = $placement;
                }
            }
        }
        return $placements;
    }

    /**
     * Id and name of every category in the subtree that is not in the menu yet.
     *
     * @param array $tree
     * @return array
     * @phpstan-param list<array<string, mixed>> $tree
     * @phpstan-return list<array{id: int, name: string}>
     */
    private function newCategories(array $tree): array
    {
        $result = [];
        foreach ($tree as $node) {
            if (empty($node['already_linked'])) {
                $result[] = ['id' => Cast::int($node['id'] ?? 0), 'name' => Cast::string($node['name'] ?? '')];
            }
            foreach ($this->newCategories($this->listOfArrays($node['children'] ?? [])) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * New categories only; children of already-linked ones are lifted to the same level (flat import).
     *
     * @param array $tree
     * @return array
     * @phpstan-param list<array<string, mixed>> $tree
     * @phpstan-return list<array<string, mixed>>
     */
    private function flattenNew(array $tree): array
    {
        $result = [];
        foreach ($tree as $node) {
            $node['children'] = $this->flattenNew($this->listOfArrays($node['children'] ?? []));
            if (empty($node['already_linked'])) {
                $result[] = $node;
                continue;
            }
            foreach ($node['children'] as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }

    /**
     * Whether a node is one of the ancestors given, or sits below one of them.
     *
     * @param array $document
     * @param string $uid
     * @param array $ancestorUids
     * @return bool
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param list<string> $ancestorUids
     */
    private function isWithin(array $document, string $uid, array $ancestorUids): bool
    {
        $current = $uid;
        while ($current !== null) {
            if (in_array($current, $ancestorUids, true)) {
                return true;
            }
            $current = $this->componentTree->findParentUid($document, $current);
        }
        return false;
    }

    /**
     * Refuse a store view the caller's role may not read (names and URLs of its categories are read in it).
     *
     * @param int $storeId
     * @return void
     * @throws AuthorizationException
     */
    public function assertStoreViewReadable(int $storeId): void
    {
        $this->storeScopeGuard->assertReadable('store view ' . $storeId, [$storeId]);
    }

    /**
     * Lookup store id.
     *
     * @param array $storeIds
     * @return int
     * @phpstan-param list<int> $storeIds
     */
    public function lookupStoreId(array $storeIds): int
    {
        foreach ($storeIds as $storeId) {
            if ($storeId > 0) {
                return $storeId;
            }
        }
        $default = $this->storeManager->getDefaultStoreView();
        return $default !== null ? Cast::int($default->getId()) : 0;
    }

    /**
     * Filter tree.
     *
     * @param array $nodes
     * @param array $linked
     * @param bool $includeInactive
     * @param bool $includeHidden
     * @param array $plan
     * @param array $names
     * @return array
     * @phpstan-param list<array<string, mixed>> $nodes
     * @phpstan-param array<int, list<string>> $linked
     * @phpstan-param array<string, mixed> $plan
     * @phpstan-param list<string> $names
     * @phpstan-return list<array<string, mixed>>
     */
    private function filterTree(
        array $nodes,
        array $linked,
        bool $includeInactive,
        bool $includeHidden,
        array &$plan,
        array &$names
    ): array {
        $kept = [];
        foreach ($nodes as $node) {
            $summary = [
                'id' => $node['id'] ?? null,
                'name' => $node['name'] ?? null,
                'level' => $node['level'] ?? null,
            ];
            if (!Cast::bool($node['active'] ?? false)) {
                $plan['inactive'][] = $summary;
                if (!$includeInactive) {
                    continue;
                }
            }
            if (!Cast::bool($node['include_in_menu'] ?? false)) {
                $plan['hidden'][] = $summary;
                if (!$includeHidden) {
                    continue;
                }
            }
            if (!Cast::bool($node['has_url_rewrite'] ?? false)) {
                $plan['missing_url_rewrites'][] = $summary;
            }
            $id = Cast::int($node['id'] ?? 0);
            if (isset($linked[$id])) {
                $plan['skip_existing'][] = $summary + ['existing_uids' => $linked[$id]];
            } else {
                $plan['create'][] = $summary;
            }
            $names[] = mb_strtolower(Cast::string($node['name'] ?? ''));
            $node['children'] = $this->filterTree(
                $this->listOfArrays($node['children'] ?? []),
                $linked,
                $includeInactive,
                $includeHidden,
                $plan,
                $names
            );
            if (!isset($linked[$id]) || $node['children'] !== []) {
                $node['already_linked'] = isset($linked[$id]);
                $node['existing_uids'] = $linked[$id] ?? [];
                $kept[] = $node;
            }
        }
        return $kept;
    }

    /**
     * Lines mentioning "menu" in layout XML / PHTML files under app/design and app/code, read once per request.
     *
     * @param string $root
     * @return array
     * @phpstan-return list<array{0: string, 1: int, 2: string}>
     */
    private function menuLinesInCode(string $root): array
    {
        if ($this->codeMenuLines !== null) {
            return $this->codeMenuLines;
        }
        $this->codeMenuLines = [];
        foreach (['app/design', 'app/code'] as $dir) {
            $path = $root . '/' . $dir;
            if (!$this->fileDriver->isDirectory($path)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !preg_match('/\.(xml|phtml)$/', $file->getFilename())
                    || str_contains($file->getPathname(), '/McpHyvaCommerceTools/')) {
                    continue;
                }
                try {
                    $lines = explode("\n", $this->fileDriver->fileGetContents($file->getPathname()));
                } catch (Throwable $e) {
                    // An unreadable template is skipped; the usage search goes on.
                    $this->logger->warning('Hyvä menu usage: could not read a template.', [
                        'file' => $file->getPathname(),
                        'exception' => $e,
                    ]);
                    $lines = [];
                }
                foreach ($lines as $number => $line) {
                    if (stripos($line, 'menu') !== false) {
                        $this->codeMenuLines[] = [substr($file->getPathname(), strlen($root) + 1), $number + 1, $line];
                    }
                }
            }
        }
        return $this->codeMenuLines;
    }

    /**
     * Layout XML / PHTML files in app/design and app/code mentioning the identifier together with "menu".
     *
     * @param string $identifier
     * @return array
     * @phpstan-return list<array{file: string, line: int}>
     */
    private function findInCodeFiles(string $identifier): array
    {
        $root = $this->directoryList->getRoot();
        $matches = [];
        foreach ($this->menuLinesInCode($root) as [$file, $number, $line]) {
            if (str_contains($line, $identifier)) {
                $matches[] = ['file' => $file, 'line' => $number];
            }
        }
        return $matches;
    }

    /**
     * List of arrays.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    private function listOfArrays(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $stringKeyed = [];
                foreach ($item as $key => $v) {
                    $stringKeyed[(string) $key] = $v;
                }
                $list[] = $stringKeyed;
            }
        }
        return $list;
    }
}
