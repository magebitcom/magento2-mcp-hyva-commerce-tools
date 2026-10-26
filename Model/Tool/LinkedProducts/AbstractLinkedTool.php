<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\LinkedProducts;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\McpHyvaCommerceTools\Api\Adapter\LinkedProductsGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for Hyvä Linked Products tools (groups of products a shopper can switch between on the product page).
 *
 * Hyvä's group services check no permissions; tools are listed with Hyva_LinkedProducts::* resources.
 *
 * @phpstan-import-type Issue from LinkedProductsGatewayInterface
 * @phpstan-import-type MemberRow from LinkedProductsGatewayInterface
 */
abstract class AbstractLinkedTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_LinkedProducts::group_view';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param LinkedProductsGatewayInterface $linkedGroups
     * @param StoreScopeGuard $storeScopeGuard
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly LinkedProductsGatewayInterface $linkedGroups,
        protected readonly StoreScopeGuard $storeScopeGuard
    ) {
    }

    /**
     * Hyvä resource the tool is listed with (catalog product access while the module is disabled).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::LINKED_PRODUCTS)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Magento_Catalog::products';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::LINKED_PRODUCTS)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::LINKED_PRODUCTS)
            );
        }
        if (static::IS_WRITE) {
            // Groups have no website or store view assignment: a restricted role may not change them.
            $this->storeScopeGuard->assertUnrestricted('Linked product groups');
        }
        return $this->executeLinked($arguments);
    }

    /**
     * Tool body once Linked Products is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeLinked(array $arguments): ToolResultInterface;

    /**
     * Inputs describing a group to create or change.
     *
     * @param ObjectBuilder $schema
     * @return ObjectBuilder
     */
    protected function withGroupChange(ObjectBuilder $schema): ObjectBuilder
    {
        $skuList = fn (string $description) => fn (ArrayBuilder $a) => $a->ofStrings()
            ->maxItems(200)->description($description);
        return $schema
            ->integer('id', fn (IntegerBuilder $i) => $i->minimum(1)
                ->description('Group to change; omit to create a new group.'))
            ->string('name', fn (StringBuilder $s) => $s->maxLength(255)
                ->description('Group name (unique). Required for a new group.'))
            ->array('attribute_codes', fn (ArrayBuilder $a) => $a->ofStrings()->maxItems(10)
                ->description('Link attributes the switcher shows (user-defined dropdowns such as color, size; '
                    . 'listed in linkable_attributes of hyva_linked.group.list). Default: unchanged.'))
            ->array('skus', $skuList('Complete member list. Default: the current members.'))
            ->array('add_skus', $skuList('Members to add to the current ones.'))
            ->array('remove_skus', $skuList('Members to remove from the current ones.'));
    }

    /**
     * Resolve the requested group change and check it.
     *
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{group_id: int|null, name: string, attribute_codes: list<string>, skus: list<string>,
     *     product_ids: list<int>, issues: list<Issue>, members: list<MemberRow>}
     */
    protected function resolveChange(array $arguments): array
    {
        $groupId = $this->intArg($arguments, 'id');
        $current = $groupId !== null ? $this->linkedGroups->get($groupId) : null;
        $name = $this->stringArg($arguments, 'name') ?? ($current['name'] ?? '');
        $codes = isset($arguments['attribute_codes'])
            ? $this->stringList($arguments['attribute_codes'])
            : array_column($current['attributes'] ?? [], 'code');
        if (isset($arguments['skus'])) {
            $skus = $this->stringList($arguments['skus']);
        } else {
            $skus = array_merge($current['skus'] ?? [], $this->stringList($arguments['add_skus'] ?? []));
            $remove = array_map('mb_strtolower', $this->stringList($arguments['remove_skus'] ?? []));
            $skus = array_filter($skus, static fn (string $sku): bool => !in_array(mb_strtolower($sku), $remove, true));
        }
        $skus = array_values(array_unique($skus));
        $ids = $this->linkedGroups->productIdsBySkus($skus);
        // SKUs match case-insensitively in the database; compare the same way and keep the stored spelling.
        $known = [];
        foreach (array_keys($ids) as $storedSku) {
            $known[mb_strtolower((string) $storedSku)] = (string) $storedSku;
        }
        $unknown = array_values(array_filter(
            $skus,
            static fn (string $sku): bool => !isset($known[mb_strtolower($sku)])
        ));
        $skus = array_values(array_unique(array_map(
            static fn (string $sku): string => $known[mb_strtolower($sku)] ?? $sku,
            $skus
        )));
        $check = $this->linkedGroups->check($groupId, $name, $codes, array_values($ids));
        if ($unknown !== []) {
            array_unshift($check['issues'], [
                'severity' => 'error',
                'code' => 'unknown_sku',
                'message' => 'No product with SKU ' . implode(', ', $unknown) . '.',
            ]);
        }
        return [
            'group_id' => $groupId,
            'name' => trim($name),
            'attribute_codes' => $codes,
            'skus' => $skus,
            'product_ids' => array_values($ids),
            'issues' => $check['issues'],
            'members' => $check['members'],
        ];
    }

    /**
     * Error issues only.
     *
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    protected function errors(array $issues): array
    {
        return array_values(array_filter($issues, static fn (array $i): bool => $i['severity'] === 'error'));
    }

    /**
     * Trimmed, non-empty strings of a list argument.
     *
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $list = [];
        foreach (Cast::array($value) as $item) {
            $item = trim(Cast::string($item));
            if ($item !== '') {
                $list[] = $item;
            }
        }
        return array_values(array_unique($list));
    }
}
