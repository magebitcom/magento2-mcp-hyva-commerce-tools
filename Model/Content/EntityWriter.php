<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Throwable;

/**
 * Creates and updates the Magento side (cms_page / cms_block) of a Hyvä CMS entity.
 * These are settings, not content: they apply immediately, unlike drafts.
 *
 * Saves run in emulated adminhtml area: the frontend-only Hyvä plugin
 * Hyva\CmsMagento\Plugin\Model\Page::afterGetContent passes a new page's null id to
 * IsValidPreview::execute(string) and throws a TypeError during Page::beforeSave.
 */
class EntityWriter
{
    public const PAGE_FIELDS = [
        'title',
        'identifier',
        'store_ids',
        'is_active',
        'page_layout',
        'content_heading',
        'meta_title',
        'meta_keywords',
        'meta_description',
    ];
    public const BLOCK_FIELDS = ['title', 'identifier', 'store_ids', 'is_active'];

    /**
     * Same default the admin page form uses (Magento\Cms\Ui\Component\Form\Field\PageLayout).
     */
    public const XML_PATH_DEFAULT_PAGE_LAYOUT = 'web/default_layouts/default_cms_layout';

    /**
     * @param PageInterfaceFactory $pageFactory
     * @param PageRepositoryInterface $pageRepository
     * @param BlockInterfaceFactory $blockFactory
     * @param BlockRepositoryInterface $blockRepository
     * @param AppState $appState
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly PageInterfaceFactory $pageFactory,
        private readonly PageRepositoryInterface $pageRepository,
        private readonly BlockInterfaceFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository,
        private readonly AppState $appState,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Create.
     *
     * @param string $type cms_page|cms_block
     * @param array $fields
     * @return int New entity id.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $fields
     */
    public function create(string $type, array $fields): int
    {
        $fields += ['store_ids' => [0], 'is_active' => false];
        if ($type === ContentLocator::TYPE_PAGE) {
            // Hyvä's preview of an inactive page reads both through string-typed getters
            // (CmsPageProvider::getPreviewPageLayout() / getPreviewContentHeading()): NULL is a TypeError.
            // Same values as the admin page form saves.
            if (!$this->isFilled($fields['page_layout'] ?? null)) {
                $fields['page_layout'] = $this->defaultPageLayout();
            }
            $fields['content_heading'] ??= '';
        }
        $entity = $type === ContentLocator::TYPE_PAGE ? $this->pageFactory->create() : $this->blockFactory->create();
        if (!$entity instanceof AbstractModel) {
            throw new LocalizedException(__('Unexpected %1 model.', $type));
        }
        $this->apply($entity, $type, $fields);
        $entity->setData('content', '');
        return $this->save($type, $entity);
    }

    /**
     * Update.
     *
     * @param string $type
     * @param int $id
     * @param array $fields
     * @return array Changed field names.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $fields
     * @phpstan-return list<string>
     */
    public function update(string $type, int $id, array $fields): array
    {
        $entity = $this->load($type, $id);
        $changed = $this->apply($entity, $type, $fields);
        if ($changed !== []) {
            $this->save($type, $entity);
        }
        return $changed;
    }

    /**
     * Stored values of settings fields that the content locator does not return (page layout, heading, meta).
     *
     * Lets the update tools tell an unchanged value from a change. Loads the entity only when a field is asked.
     *
     * @param string $type cms_page|cms_block
     * @param int $id
     * @param array $fields
     * @return array Field => stored value; NULL is returned as '' (the value a write of '' stores).
     * @throws LocalizedException
     * @phpstan-param list<string> $fields
     * @phpstan-return array<string, mixed>
     */
    public function currentValues(string $type, int $id, array $fields): array
    {
        $allowed = $type === ContentLocator::TYPE_PAGE ? self::PAGE_FIELDS : self::BLOCK_FIELDS;
        $fields = array_values(array_intersect($fields, array_diff($allowed, ['store_ids', 'is_active'])));
        if ($fields === []) {
            return [];
        }
        $entity = $this->load($type, $id);
        $values = [];
        foreach ($fields as $name) {
            $value = $entity->getData($name);
            $values[$name] = is_scalar($value) ? (string) $value : '';
        }
        return $values;
    }

    /**
     * Load the Magento page or block model.
     *
     * @param string $type
     * @param int $id
     * @return AbstractModel
     * @throws LocalizedException
     */
    private function load(string $type, int $id): AbstractModel
    {
        $entity = $type === ContentLocator::TYPE_PAGE
            ? $this->pageRepository->getById($id)
            : $this->blockRepository->getById((string) $id);
        if (!$entity instanceof AbstractModel) {
            throw new LocalizedException(__('Unexpected %1 model.', $type));
        }
        return $entity;
    }

    /**
     * Apply.
     *
     * @param AbstractModel $entity
     * @param string $type
     * @param array $fields
     * @return array
     * @phpstan-param array<string, mixed> $fields
     * @phpstan-return list<string>
     */
    private function apply(AbstractModel $entity, string $type, array $fields): array
    {
        $allowed = $type === ContentLocator::TYPE_PAGE ? self::PAGE_FIELDS : self::BLOCK_FIELDS;
        $changed = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, $allowed, true) || $value === null) {
                continue;
            }
            if ($name === 'page_layout' && !$this->isFilled($value)) {
                throw new LocalizedException(__('page_layout cannot be empty; omit it to keep the current layout.'));
            }
            if ($name === 'store_ids') {
                $ids = array_values(array_map('intval', is_array($value) ? $value : [$value]));
                $entity->setData('store_id', $ids);
                $entity->setData('stores', $ids);
            } elseif ($name === 'is_active') {
                $entity->setData('is_active', (int) (bool) $value);
            } else {
                $entity->setData($name, is_scalar($value) ? (string) $value : '');
            }
            $changed[] = $name;
        }
        return $changed;
    }

    /**
     * Save.
     *
     * @param string $type
     * @param AbstractModel $entity
     * @return int
     * @throws LocalizedException
     */
    private function save(string $type, AbstractModel $entity): int
    {
        try {
            $saved = $this->appState->emulateAreaCode(
                Area::AREA_ADMINHTML,
                fn () => match (true) {
                    $entity instanceof PageInterface => $this->pageRepository->save($entity),
                    $entity instanceof BlockInterface => $this->blockRepository->save($entity),
                    default => throw new LocalizedException(__('Unexpected %1 model.', $type)),
                }
            );
        } catch (LocalizedException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LocalizedException(__('Could not save the %1: %2', $type, $e->getMessage()));
        }
        return is_object($saved) && method_exists($saved, 'getId') ? (int) $saved->getId() : 0;
    }

    /**
     * Default CMS page layout from config, as the admin form pre-fills it.
     *
     * @return string
     */
    public function defaultPageLayout(): string
    {
        $layout = $this->scopeConfig->getValue(self::XML_PATH_DEFAULT_PAGE_LAYOUT);
        return is_scalar($layout) && trim((string) $layout) !== '' ? (string) $layout : '1column';
    }

    /**
     * Is filled.
     *
     * @param mixed $value
     * @return bool
     */
    private function isFilled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
