<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMerch\Category;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Merchandising\CategoryPositions;
use Magebit\McpHyvaCommerceTools\Model\Security\AdminScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

class PositionsSet extends AbstractTool
{
    public const TOOL_NAME = 'hyva_merch.category.positions_set';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_merch_category_positions_set';
    public const UNDERLYING_ACL_RESOURCE = 'Magento_Catalog::categories';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param CategoryPositions $categoryPositions
     * @param AdminScopeGuard $scopeGuard
     * @param CallerContext $callerContext
     */
    public function __construct(
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly CategoryPositions $categoryPositions,
        private readonly AdminScopeGuard $scopeGuard,
        private readonly CallerContext $callerContext
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Reorder Category Products';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Put products first in a category, as the Hyvä Category Merchandiser does: the given SKUs move to the '
            . 'top in that order, every other product keeps its relative order after them. Only reorders (never '
            . 'adds or removes products). Positions are global (all store views). Without confirm=true you get the '
            . 'before/after order and storefront notes.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('category_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
            ->array('top_skus', fn (ArrayBuilder $a) => $a->ofStrings()->minItems(1)->maxItems(100)->required()
                ->description('SKUs to show first, in order.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Save after reviewing the preview.'))
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
        if ($this->scopeGuard->allowedScopes($this->adminUserId())['restricted']) {
            throw new AuthorizationException(__('Category positions apply to every store view; a role restricted to '
                . 'some websites or store views may not change them.'));
        }
        $categoryId = $this->intArg($arguments, 'category_id') ?? 0;
        $skus = array_values(array_filter(
            array_map(
                static fn (mixed $sku): string => trim(Cast::string($sku)),
                Cast::array($arguments['top_skus'] ?? [])
            ),
            static fn (string $sku): bool => $sku !== ''
        ));
        try {
            $plan = $this->categoryPositions->plan($categoryId, $skus);
        } catch (LocalizedException $e) {
            return $this->softError($e->getMessage());
        }
        $preview = [
            'category_id' => $categoryId,
            'moved' => $plan['moved'],
            'new_top' => array_slice($plan['after'], 0, max(10, count($skus))),
            'notes' => $this->categoryPositions->notes(),
        ];
        if ($plan['moved'] === 0) {
            return $this->jsonResult(['saved' => false, 'note' => 'The products are already in that order.']
                + $preview, ['category_id' => $categoryId, 'moved' => 0]);
        }
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('reorder_category_products', [$preview]);
        }
        $this->categoryPositions->save($categoryId, $plan['positions']);
        return $this->jsonResult(['saved' => true] + $preview, [
            'category_id' => $categoryId,
            'moved' => $plan['moved'],
        ]);
    }

    /**
     * Admin user id of the MCP caller.
     *
     * @return int|null
     */
    private function adminUserId(): ?int
    {
        return $this->callerContext->adminUserId();
    }
}
