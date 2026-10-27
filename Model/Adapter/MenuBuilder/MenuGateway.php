<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\MenuBuilder;

use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\MenuBuilder\Api\Data\MenuInterface;
use Hyva\MenuBuilder\Api\Data\MenuInterfaceFactory;
use Hyva\MenuBuilder\Api\MenuRepositoryInterface;
use Hyva\MenuBuilder\Model\ResourceModel\Menu\CollectionFactory;
use Hyva\MenuBuilder\ViewModel\MenuRenderer;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Throwable;

class MenuGateway implements MenuGatewayInterface
{
    private const IDENTIFIER_PATTERN = '/^[a-z0-9_]+$/';
    private const PREVIEW_KEY_PATTERN = '/^[a-z0-9\-\_\/]+(.html)?$/';

    /**
     * @param OptionalServiceFactory $optionalServices
     * @param Emulation $emulation
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly Emulation $emulation
    ) {
    }

    /**
     * @inheritDoc
     */
    public function create(array $data): int
    {
        $menu = $this->menuFactory()->create();
        $this->apply($menu, $data + ['is_active' => false]);
        return $this->save($menu);
    }

    /**
     * @inheritDoc
     */
    public function update(int $menuId, array $data): array
    {
        try {
            $menu = $this->menuRepository()->get($menuId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Menu #%1 does not exist.', $menuId));
        }
        $changed = $this->apply($menu, $data);
        if ($changed !== []) {
            $this->save($menu);
        }
        return $changed;
    }

    /**
     * @inheritDoc
     */
    public function render(string $identifier, int $storeId): array
    {
        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            $result = $this->menuRenderer()->renderMenu($identifier);
            $html = Cast::string($result['html'] ?? '');
            $message = isset($result['message']) ? Cast::string($result['message']) : null;
            return ['html' => $html, 'message' => $message, 'error' => null];
        } catch (Throwable $e) {
            return ['html' => '', 'message' => null, 'error' => $e->getMessage()];
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * Apply.
     *
     * @param MenuInterface $menu
     * @param array $data
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $data
     * @phpstan-return list<string>
     */
    private function apply(MenuInterface $menu, array $data): array
    {
        $changed = [];
        if (isset($data['title'])) {
            $title = Cast::string($data['title']);
            if (mb_strlen($title) < 2) {
                throw new LocalizedException(__('Menu title must have at least 2 characters.'));
            }
            $menu->setTitle($title);
            $changed[] = 'title';
        }
        if (isset($data['identifier'])) {
            $identifier = Cast::string($data['identifier']);
            if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1 || strlen($identifier) < 2) {
                throw new LocalizedException(
                    __('Menu identifier "%1" must match [a-z0-9_]+ (min 2 characters).', $identifier)
                );
            }
            $menu->setIdentifier($identifier);
            $changed[] = 'identifier';
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $menu->setIsActive(Cast::bool($data['is_active']));
            $changed[] = 'is_active';
        }
        if (array_key_exists('preview_url_key', $data)) {
            $key = Cast::nullableString($data['preview_url_key']);
            if ($key !== null && $key !== '' && preg_match(self::PREVIEW_KEY_PATTERN, $key) !== 1) {
                throw new LocalizedException(__('preview_url_key "%1" may only contain a-z, 0-9, -, _ and /.', $key));
            }
            $menu->setPreviewUrlKey($key === '' ? null : $key);
            $changed[] = 'preview_url_key';
        }
        $stores = Cast::intList($data['store_ids'] ?? null);
        if ($stores === [] && $menu->getId() === null) {
            throw new LocalizedException(__('A menu needs at least one store view (store_ids, 0 = all).'));
        }
        if ($stores !== []) {
            $menu->setStores($stores);
            $changed[] = 'store_ids';
        }
        return $changed;
    }

    /**
     * Save.
     *
     * @param MenuInterface $menu
     * @return int
     * @throws LocalizedException
     */
    private function save(MenuInterface $menu): int
    {
        try {
            return Cast::int($this->menuRepository()->save($menu)->getId());
        } catch (LocalizedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LocalizedException(__('Could not save the menu: %1', $e->getMessage()));
        }
    }

    /**
     * @inheritDoc
     */
    public function getList(array $filters = []): array
    {
        $collection = $this->collectionFactory()->create();
        $storeId = $filters['store_id'] ?? null;
        if ($storeId !== null) {
            $collection->addStoreFilter($storeId);
        }
        $isActive = $filters['is_active'] ?? null;
        if ($isActive !== null) {
            $collection->addFieldToFilter(MenuInterface::IS_ACTIVE, (int) $isActive);
        }
        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $like = '%' . addcslashes($search, '%_') . '%';
            $collection->addFieldToFilter(
                [MenuInterface::TITLE, MenuInterface::IDENTIFIER],
                [['like' => $like], ['like' => $like]]
            );
        }
        $collection->setOrder(MenuInterface::ID, 'ASC');

        $rows = [];
        foreach ($collection->getItems() as $menu) {
            if ($menu instanceof MenuInterface) {
                $rows[] = $this->toArray($menu);
            }
        }
        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function get(int $menuId): array
    {
        try {
            return $this->toArray($this->menuRepository()->get($menuId));
        } catch (NoSuchEntityException) {
            throw new LocalizedException(__('Menu #%1 does not exist.', $menuId));
        }
    }

    /**
     * @inheritDoc
     */
    public function findIdByIdentifier(string $identifier, ?int $storeId = null): ?int
    {
        if ($storeId !== null) {
            try {
                return (int) $this->menuRepository()->getByIdentifier($identifier, $storeId)->getId();
            } catch (NoSuchEntityException) {
                return null;
            }
        }
        $collection = $this->collectionFactory()->create();
        $collection->addFieldToFilter(MenuInterface::IDENTIFIER, $identifier);
        $collection->setPageSize(1);
        $menu = $collection->getFirstItem();
        return $menu instanceof MenuInterface && $menu->getId() ? (int) $menu->getId() : null;
    }

    /**
     * To array.
     *
     * @param MenuInterface $menu
     * @return array
     * @phpstan-return array<string, mixed>
     */
    private function toArray(MenuInterface $menu): array
    {
        $draft = (string) $menu->getDraftContent();
        $published = (string) $menu->getPublishedContent();
        $storeIds = [];
        foreach (Cast::intList($menu->getStores()) as $storeId) {
            $storeIds[] = $storeId;
        }

        return [
            'id' => (int) $menu->getId(),
            'title' => (string) $menu->getTitle(),
            'identifier' => (string) $menu->getIdentifier(),
            'is_active' => $menu->isActive(),
            'store_ids' => $storeIds,
            'preview_url_key' => $menu->getPreviewUrlKey(),
            'has_draft' => $draft !== '' && $draft !== '[]',
            'is_published' => $published !== '' && $published !== '[]',
            'draft_differs' => $draft !== $published,
            'creation_time' => $menu->getCreationTime(),
            'update_time' => $menu->getUpdateTime(),
        ];
    }

    /**
     * MenuRepositoryInterface from the optional package, created on first use.
     *
     * @return MenuRepositoryInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function menuRepository(): MenuRepositoryInterface
    {
        return $this->optionalServices->get(MenuRepositoryInterface::class);
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
     * MenuInterfaceFactory from the optional package, created on first use.
     *
     * @return MenuInterfaceFactory
     * @throws LocalizedException When the package is not installed.
     */
    private function menuFactory(): MenuInterfaceFactory
    {
        return $this->optionalServices->get(MenuInterfaceFactory::class);
    }

    /**
     * MenuRenderer from the optional package, created on first use.
     *
     * @return MenuRenderer
     * @throws LocalizedException When the package is not installed.
     */
    private function menuRenderer(): MenuRenderer
    {
        return $this->optionalServices->get(MenuRenderer::class);
    }
}
