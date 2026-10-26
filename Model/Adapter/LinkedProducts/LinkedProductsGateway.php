<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\LinkedProducts;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\LinkedProducts\Model\Attribute\Source\ListSelectAttributes;
use Hyva\LinkedProducts\Model\Duplicate\DefaultScopeReachabilityReader;
use Hyva\LinkedProducts\Model\Duplicate\DuplicateCombinationDetector;
use Hyva\LinkedProducts\Model\LinkedGroupSaver;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup as LinkedGroupResource;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\Attribute as AttributeResource;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\CollectionFactory;
use Hyva\LinkedProducts\Model\ResourceModel\LinkedGroup\Product as ProductResource;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Catalog\Model\Product;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * Adapter over Hyvä Linked Products 1.0.x.
 *
 * Hyvä has no validate API (LinkedGroupSaver's checks are private), so check() repeats them with the same public
 * resource methods and adds the storefront warnings the admin form shows (members not on the storefront, members
 * with the same link attribute values). LinkedGroupSaver cleans no cache; save() refreshes the product pages.
 *
 * @phpstan-import-type GroupRow from LinkedProductsGatewayInterface
 * @phpstan-import-type MemberRow from LinkedProductsGatewayInterface
 * @phpstan-import-type Issue from LinkedProductsGatewayInterface
 */
class LinkedProductsGateway implements LinkedProductsGatewayInterface
{
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param CacheContextFactory $cacheContextFactory
     * @param EventManager $eventManager
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly EventManager $eventManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getList(?string $search, ?string $sku, int $page, int $pageSize): array
    {
        $collection = $this->collectionFactory()->create();
        if ($search !== null && $search !== '') {
            $collection->addFieldToFilter('group_name', ['like' => '%' . addcslashes($search, '%_') . '%']);
        }
        if ($sku !== null && $sku !== '') {
            // Keyed by the stored spelling; SKUs match case-insensitively.
            $found = $this->productResource()->findEntityIdsBySkus([$sku]);
            $productId = (int) (reset($found) ?: 0);
            $groupId = $productId > 0 ? $this->productResource()->findGroupIdByProductId($productId) : null;
            $collection->addFieldToFilter('group_id', ['eq' => $groupId ?? 0]);
        }
        $collection->setOrder('group_id', 'DESC')->setCurPage($page)->setPageSize($pageSize);
        $rows = [];
        foreach ($collection->getItems() as $group) {
            $rows[Cast::int($group->getData('group_id'))] = [
                'name' => Cast::string($group->getData('group_name')),
                'created_at' => Cast::string($group->getData('created_at')),
                'updated_at' => Cast::string($group->getData('updated_at')),
            ];
        }
        return ['total' => $collection->getSize(), 'items' => $this->compose($rows)];
    }

    /**
     * @inheritDoc
     */
    public function get(int $groupId): array
    {
        $group = $this->collectionFactory()->create()->addFieldToFilter('group_id', ['eq' => $groupId])->getFirstItem();
        if (!$group->getData('group_id')) {
            throw new LocalizedException(__('There is no linked product group with id %1.', $groupId));
        }
        return $this->compose([$groupId => [
            'name' => Cast::string($group->getData('group_name')),
            'created_at' => Cast::string($group->getData('created_at')),
            'updated_at' => Cast::string($group->getData('updated_at')),
        ]])[0];
    }

    /**
     * @inheritDoc
     */
    public function findIdByName(string $name): ?int
    {
        $ids = $this->groupResource()->findGroupIdsByName(trim($name));
        return $ids === [] ? null : Cast::int(reset($ids));
    }

    /**
     * @inheritDoc
     */
    public function productIdsBySkus(array $skus): array
    {
        return $skus === [] ? [] : $this->productResource()->findEntityIdsBySkus($skus);
    }

    /**
     * @inheritDoc
     */
    public function linkableAttributes(): array
    {
        $attributes = [];
        foreach ($this->listSelectAttributes()->getAllOptions() as $option) {
            $code = Cast::string($option['value'] ?? '');
            $attributes[] = ['code' => $code, 'label' => Cast::string($option['label'] ?? '') ?: $code];
        }
        return $attributes;
    }

    /**
     * @inheritDoc
     */
    public function check(?int $groupId, string $name, array $attributeCodes, array $productIds): array
    {
        $issues = [];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > LinkedGroupSaver::MAX_NAME_LENGTH) {
            $issues[] = $this->issue('error', 'invalid_name', sprintf(
                'The group name is required and can be at most %d characters.',
                LinkedGroupSaver::MAX_NAME_LENGTH
            ));
        } elseif (array_diff($this->groupResource()->findGroupIdsByName($name), $groupId === null ? [] : [$groupId])) {
            $issues[] = $this->issue('error', 'name_taken', sprintf('A group named "%s" already exists.', $name));
        }
        $productIds = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if (count($productIds) < LinkedGroupSaver::MIN_MEMBERS) {
            $issues[] = $this->issue('error', 'too_few_members', sprintf(
                'A group needs at least %d different products; this one has %d.',
                LinkedGroupSaver::MIN_MEMBERS,
                count($productIds)
            ));
        }
        $meta = $this->attributeResource()->findProductAttributeMetaByCodes($attributeCodes);
        $refused = array_values(array_filter(
            $attributeCodes,
            static fn (string $code): bool => !($meta[$code]['is_linkable'] ?? false)
        ));
        if ($refused !== []) {
            $issues[] = $this->issue('error', 'attribute_not_linkable', 'Only user-defined dropdown product '
                . 'attributes can link a group; these cannot: ' . implode(', ', $refused) . '.');
        }
        if ($attributeCodes === []) {
            $issues[] = $this->issue('warning', 'no_link_attributes', 'Without link attributes the storefront '
                . 'switcher has no option to show for each product.');
        }
        $missing = array_values(array_diff($productIds, $this->productResource()->findExistingEntityIds($productIds)));
        if ($missing !== []) {
            $issues[] = $this->issue('error', 'product_missing', 'These product ids do not exist: '
                . implode(', ', $missing) . '.');
        }
        $elsewhere = [];
        foreach ($this->productResource()->findGroupIdsByProductIds($productIds) as $productId => $memberOf) {
            if ($memberOf !== $groupId) {
                $elsewhere[] = Cast::int($productId);
            }
        }
        if ($elsewhere !== []) {
            $issues[] = $this->issue('error', 'in_other_group', 'These products already belong to another group '
                . '(a product can be in one group only; remove them there first): '
                . implode(', ', $this->productResource()->findSkusByEntityIds($elsewhere)) . '.');
        }
        [$members, $optionIds] = $this->members(array_values(array_diff($productIds, $missing)), $attributeCodes);
        $hidden = array_values(array_filter($members, static fn (array $m): bool => !$m['on_storefront']));
        if ($hidden !== []) {
            $issues[] = $this->issue('warning', 'member_not_on_storefront', 'Not shown in the switcher: '
                . implode(', ', array_map(
                    static fn (array $m): string => $m['sku'] . ' (' . $m['not_shown_because'] . ')',
                    $hidden
                )) . '.');
        }
        foreach ($this->collisions($optionIds, $attributeCodes) as $skus) {
            $issues[] = $this->issue('warning', 'same_link_values', sprintf(
                'SKUs %s have the same %s values, so shoppers cannot tell them apart; the storefront shows the one '
                . 'with the lowest id.',
                implode(', ', $skus),
                implode(' / ', $attributeCodes)
            ));
        }
        return ['issues' => $issues, 'members' => $members];
    }

    /**
     * @inheritDoc
     */
    public function save(?int $groupId, string $name, array $attributeCodes, array $productIds): int
    {
        $before = [];
        if ($groupId !== null) {
            $before = array_map(
                static fn (array $entry): int => Cast::int($entry['product_id']),
                $this->productResource()->findEntriesByGroupId($groupId)
            );
        }
        try {
            $savedId = $this->saver()->save($groupId, $name, $attributeCodes, $productIds);
        } catch (DuplicateException) {
            throw new LocalizedException(__('A group with this name was just created by someone else; choose a '
                . 'different name.'));
        }
        // Products that joined or left the group render a different switcher.
        $cacheContext = $this->cacheContextFactory->create();
        $cacheContext->registerEntities(Product::CACHE_TAG, array_values(array_unique(array_merge(
            $before,
            $productIds
        ))));
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);
        return $savedId;
    }

    /**
     * Add link attributes and member SKUs to group rows (two queries for the page).
     *
     * @param array $rows
     * @return array
     * @phpstan-param array<int, array{name: string, created_at: string, updated_at: string}> $rows
     * @phpstan-return list<GroupRow>
     */
    private function compose(array $rows): array
    {
        $ids = array_keys($rows);
        $attributes = $this->attributeResource()->findAttributesByGroupIds($ids);
        $skus = $this->productResource()->findSkusByGroupIds($ids);
        $groups = [];
        foreach ($rows as $id => $row) {
            $groupSkus = array_values($skus[$id] ?? []);
            $groups[] = [
                'id' => $id,
                'name' => $row['name'],
                'attributes' => array_values(array_map(
                    static fn (array $a): array => ['code' => $a['code'], 'label' => $a['label'] ?: $a['code']],
                    $attributes[$id] ?? []
                )),
                'member_count' => count($groupSkus),
                'skus' => $groupSkus,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $groups;
    }

    /**
     * Members with their storefront eligibility and link attribute values (default scope, one bulk read).
     *
     * Also returns the option ids of storefront members by SKU, which is what Hyvä compares for duplicates.
     *
     * @param list<int> $productIds
     * @param list<string> $attributeCodes
     * @return array{0: list<MemberRow>, 1: array<string, array<string, string>>}
     */
    private function members(array $productIds, array $attributeCodes): array
    {
        if ($productIds === []) {
            return [[], []];
        }
        $skus = $this->productResource()->findSkusByEntityIds($productIds);
        $members = [];
        $optionIds = [];
        foreach ($this->reachabilityReader()->read($productIds) as $productId => $entry) {
            $values = [];
            foreach ($attributeCodes as $code) {
                $values[$code] = Cast::string($entry['labels'][$code] ?? '');
                if ($entry['eligible']) {
                    $key = $skus[$productId] ?? (string) $productId;
                    $optionIds[$key][$code] = Cast::string($entry['ids'][$code] ?? '');
                }
            }
            $members[] = [
                'product_id' => Cast::int($productId),
                'sku' => $skus[$productId] ?? '',
                'on_storefront' => $entry['eligible'],
                'not_shown_because' => $entry['ineligibleReason'],
                'values' => $values,
            ];
        }
        return [$members, $optionIds];
    }

    /**
     * SKU sets of storefront members that share every link attribute option, as Hyvä's importer reports them.
     *
     * @param array $optionIds
     * @param string[] $attributeCodes
     * @return array
     * @phpstan-param array<string, array<string, string>> $optionIds
     * @phpstan-param list<string> $attributeCodes
     * @phpstan-return list<list<string>>
     */
    private function collisions(array $optionIds, array $attributeCodes): array
    {
        $sets = [];
        foreach ($this->duplicateDetector()->findCollidingSets($attributeCodes, $optionIds) as $set) {
            $sets[] = array_map('strval', array_values($set));
        }
        return $sets;
    }

    /**
     * Issue row.
     *
     * @param string $severity
     * @param string $code
     * @param string $message
     * @return array
     * @phpstan-return Issue
     */
    private function issue(string $severity, string $code, string $message): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message];
    }

    /**
     * CollectionFactory from the optional package, created on first use.
     *
     * @return CollectionFactory
     * @throws LocalizedException When the package is not installed.
     */
    private function collectionFactory(): CollectionFactory
    {
        return $this->optionalServices->get(CollectionFactory::class);
    }

    /**
     * LinkedGroupResource from the optional package, created on first use.
     *
     * @return LinkedGroupResource
     * @throws LocalizedException When the package is not installed.
     */
    private function groupResource(): LinkedGroupResource
    {
        return $this->optionalServices->get(LinkedGroupResource::class);
    }

    /**
     * AttributeResource from the optional package, created on first use.
     *
     * @return AttributeResource
     * @throws LocalizedException When the package is not installed.
     */
    private function attributeResource(): AttributeResource
    {
        return $this->optionalServices->get(AttributeResource::class);
    }

    /**
     * ProductResource from the optional package, created on first use.
     *
     * @return ProductResource
     * @throws LocalizedException When the package is not installed.
     */
    private function productResource(): ProductResource
    {
        return $this->optionalServices->get(ProductResource::class);
    }

    /**
     * ListSelectAttributes from the optional package, created on first use.
     *
     * @return ListSelectAttributes
     * @throws LocalizedException When the package is not installed.
     */
    private function listSelectAttributes(): ListSelectAttributes
    {
        return $this->optionalServices->get(ListSelectAttributes::class);
    }

    /**
     * DefaultScopeReachabilityReader from the optional package, created on first use.
     *
     * @return DefaultScopeReachabilityReader
     * @throws LocalizedException When the package is not installed.
     */
    private function reachabilityReader(): DefaultScopeReachabilityReader
    {
        return $this->optionalServices->get(DefaultScopeReachabilityReader::class);
    }

    /**
     * DuplicateCombinationDetector from the optional package, created on first use.
     *
     * @return DuplicateCombinationDetector
     * @throws LocalizedException When the package is not installed.
     */
    private function duplicateDetector(): DuplicateCombinationDetector
    {
        return $this->optionalServices->get(DuplicateCombinationDetector::class);
    }

    /**
     * LinkedGroupSaver from the optional package, created on first use.
     *
     * @return LinkedGroupSaver
     * @throws LocalizedException When the package is not installed.
     */
    private function saver(): LinkedGroupSaver
    {
        return $this->optionalServices->get(LinkedGroupSaver::class);
    }
}
