<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMerch\Category;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Merchandising\CategoryPositions;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;

class PositionsGet extends AbstractTool
{
    public const TOOL_NAME = 'hyva_merch.category.positions_get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_merch_category_positions_get';
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Catalog::categories';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param CategoryPositions $categoryPositions
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly CategoryPositions $categoryPositions
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Category Product Order';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Show the merchandised product order of a category (Hyvä Category Merchandiser: the stored category '
            . 'product positions), with SKU, name, enabled and visible flags, plus notes when something else '
            . 'controls the storefront order (e.g. ElasticSuite) or the index updates by schedule.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('category_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(500)->description('Default 100.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::CATEGORY_MERCHANDISER)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::CATEGORY_MERCHANDISER)
            );
        }
        $result = $this->categoryPositions->get(
            $this->intArg($arguments, 'category_id') ?? 0,
            $this->intArg($arguments, 'limit', 100) ?? 100
        );
        return $this->jsonResult($result, ['category_id' => $result['category']['id']]);
    }
}
