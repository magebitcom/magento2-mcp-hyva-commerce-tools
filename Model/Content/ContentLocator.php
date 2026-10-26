<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Exception\FeatureUnavailableException;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\ResourceModel\Block\CollectionFactory as BlockCollectionFactory;
use Magento\Cms\Model\ResourceModel\Page\CollectionFactory as PageCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;

/**
 * Resolves a Hyvä CMS entity (cms_page, cms_block, menu) from tool arguments: `id`, or `identifier` + optional
 * `store_id`.
 */
class ContentLocator
{
    public const TYPE_PAGE = 'cms_page';
    public const TYPE_BLOCK = 'cms_block';
    public const TYPE_MENU = 'menu';
    public const TYPES = [self::TYPE_PAGE, self::TYPE_BLOCK, self::TYPE_MENU];

    /**
     * @param PageRepositoryInterface $pageRepository
     * @param BlockRepositoryInterface $blockRepository
     * @param PageCollectionFactory $pageCollectionFactory
     * @param BlockCollectionFactory $blockCollectionFactory
     * @param MenuGatewayInterface $menuGateway
     * @param FeatureCheckerInterface $featureChecker
     */
    public function __construct(
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly PageCollectionFactory $pageCollectionFactory,
        private readonly BlockCollectionFactory $blockCollectionFactory,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly FeatureCheckerInterface $featureChecker
    ) {
    }

    /**
     * Locate.
     *
     * @param string $type cms_page|cms_block|menu
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>}
     */
    public function locate(string $type, array $arguments): array
    {
        $id = $arguments['id'] ?? null;
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
        $identifier = is_string($arguments['identifier'] ?? null) && $arguments['identifier'] !== ''
            ? $arguments['identifier']
            : null;
        $storeId = is_int($arguments['store_id'] ?? null) ? $arguments['store_id'] : null;

        if ($id === null && $identifier === null) {
            throw new LocalizedException(__('Pass either "id" or "identifier" to select the %1.', $type));
        }

        return match ($type) {
            self::TYPE_PAGE => $this->fromPage($this->loadPage($id, $identifier, $storeId)),
            self::TYPE_BLOCK => $this->fromBlock($this->loadBlock($id, $identifier, $storeId)),
            self::TYPE_MENU => $this->locateMenu($id, $identifier, $storeId),
            default => throw new LocalizedException(
                __('Unsupported entity type "%1". Use one of: %2.', $type, implode(', ', self::TYPES))
            ),
        };
    }

    /**
     * Load page.
     *
     * @param int|null $id
     * @param string|null $identifier
     * @param int|null $storeId
     * @return PageInterface
     * @throws LocalizedException
     */
    private function loadPage(?int $id, ?string $identifier, ?int $storeId): PageInterface
    {
        if ($id !== null) {
            try {
                return $this->pageRepository->getById($id);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('CMS page #%1 does not exist.', $id));
            }
        }
        $collection = $this->pageCollectionFactory->create();
        $collection->addFieldToFilter('identifier', (string) $identifier);
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }
        $ids = array_map('intval', $collection->getAllIds());
        if ($ids === []) {
            throw new LocalizedException(__('CMS page "%1" does not exist.', (string) $identifier));
        }
        if ($storeId !== null) {
            // The store filter leaves at most two candidates (store-specific and all store views); disabled
            // entities stay included, unlike GetPageByIdentifier/GetBlockByIdentifier.
            $candidates = [];
            foreach ($ids as $candidateId) {
                $candidates[] = $this->pageRepository->getById($candidateId);
            }
            return $this->preferStoreSpecific($candidates, $storeId);
        }
        if (count($ids) > 1) {
            throw new LocalizedException(__(
                'Several CMS pages use identifier "%1" (ids %2). Pass id or store_id.',
                (string) $identifier,
                implode(', ', $ids)
            ));
        }
        return $this->pageRepository->getById((int) $ids[0]);
    }

    /**
     * Load block.
     *
     * @param int|null $id
     * @param string|null $identifier
     * @param int|null $storeId
     * @return BlockInterface
     * @throws LocalizedException
     */
    private function loadBlock(?int $id, ?string $identifier, ?int $storeId): BlockInterface
    {
        if ($id !== null) {
            try {
                return $this->blockRepository->getById((string) $id);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('CMS block #%1 does not exist.', $id));
            }
        }
        $collection = $this->blockCollectionFactory->create();
        $collection->addFieldToFilter('identifier', (string) $identifier);
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }
        $ids = array_map('intval', $collection->getAllIds());
        if ($ids === []) {
            throw new LocalizedException(__('CMS block "%1" does not exist.', (string) $identifier));
        }
        if ($storeId !== null) {
            // The store filter leaves at most two candidates (store-specific and all store views); disabled
            // entities stay included, unlike GetPageByIdentifier/GetBlockByIdentifier.
            $candidates = [];
            foreach ($ids as $candidateId) {
                $candidates[] = $this->blockRepository->getById((string) $candidateId);
            }
            return $this->preferStoreSpecific($candidates, $storeId);
        }
        if (count($ids) > 1) {
            throw new LocalizedException(__(
                'Several CMS blocks use identifier "%1" (ids %2). Pass id or store_id.',
                (string) $identifier,
                implode(', ', $ids)
            ));
        }
        return $this->blockRepository->getById((string) $ids[0]);
    }

    /**
     * Locate menu.
     *
     * @param int|null $id
     * @param string|null $identifier
     * @param int|null $storeId
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>}
     */
    private function locateMenu(?int $id, ?string $identifier, ?int $storeId): array
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
            throw new FeatureUnavailableException(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::MENU_BUILDER)
            );
        }
        if ($id === null) {
            $id = $this->menuGateway->findIdByIdentifier((string) $identifier, $storeId);
            if ($id === null) {
                throw new LocalizedException(__('Menu "%1" does not exist.', (string) $identifier));
            }
        }
        $menu = $this->menuGateway->get($id);
        $storeIds = [];
        foreach (is_array($menu['store_ids'] ?? null) ? $menu['store_ids'] : [] as $menuStoreId) {
            $storeIds[] = (int) $menuStoreId;
        }
        return [
            'type' => self::TYPE_MENU,
            'id' => $id,
            'identifier' => is_string($menu['identifier'] ?? null) ? $menu['identifier'] : '',
            'title' => is_string($menu['title'] ?? null) ? $menu['title'] : '',
            'is_active' => (bool) ($menu['is_active'] ?? false),
            'store_ids' => $storeIds,
        ];
    }

    /**
     * From page.
     *
     * @param PageInterface $page
     * @return array
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>}
     */
    private function fromPage(PageInterface $page): array
    {
        return [
            'type' => self::TYPE_PAGE,
            'id' => (int) $page->getId(),
            'identifier' => (string) $page->getIdentifier(),
            'title' => (string) $page->getTitle(),
            'is_active' => (bool) $page->isActive(),
            'store_ids' => $this->storeIds($page instanceof AbstractModel ? $page->getData('store_id') : []),
        ];
    }

    /**
     * From block.
     *
     * @param BlockInterface $block
     * @return array
     * @phpstan-return array{type: string, id: int, identifier: string, title: string, is_active: bool,
     *     store_ids: list<int>}
     */
    private function fromBlock(BlockInterface $block): array
    {
        return [
            'type' => self::TYPE_BLOCK,
            'id' => (int) $block->getId(),
            'identifier' => (string) $block->getIdentifier(),
            'title' => (string) $block->getTitle(),
            'is_active' => (bool) $block->isActive(),
            'store_ids' => $this->storeIds($block instanceof AbstractModel ? $block->getData('store_id') : []),
        ];
    }

    /**
     * Store ids.
     *
     * @param mixed $value
     * @return array
     * @phpstan-return list<int>
     */
    private function storeIds(mixed $value): array
    {
        $ids = [];
        foreach ((array) $value as $storeId) {
            if (is_numeric($storeId)) {
                $ids[] = (int) $storeId;
            }
        }
        return $ids;
    }

    /**
     * Pick the entity assigned to the store itself over the one assigned to all store views.
     *
     * @param array $candidates
     * @param int $storeId
     * @return PageInterface|BlockInterface
     * @phpstan-template T of PageInterface|BlockInterface
     * @phpstan-param non-empty-list<T> $candidates
     * @phpstan-return T
     */
    private function preferStoreSpecific(array $candidates, int $storeId): PageInterface|BlockInterface
    {
        foreach ($candidates as $candidate) {
            $stores = $candidate instanceof AbstractModel ? $candidate->getData('store_id') : [];
            if (in_array($storeId, $this->storeIds($stores), true)) {
                return $candidate;
            }
        }
        return $candidates[0];
    }
}
