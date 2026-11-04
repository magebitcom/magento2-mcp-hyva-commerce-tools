<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Menu options of the hyva_menu_widget component, cut to the menus the caller may see (T-148).
 *
 * Same rule as hyva_menu_list and the schedule tools: no menu for a caller who may not view menus
 * (ContentScopeFilter::viewableTypes()), and for a restricted caller only menus of store views it may read. Hyvä keys
 * the options by menu identifier, which several store views can share, and names the menu id in the label
 * ("Title (ID: 3)"): an option stays when the menu its label names is readable, or, for a label without an id (e.g.
 * translated), when one menu with its identifier is. The empty placeholder option always stays.
 */
class MenuOptionScope
{
    public const COMPONENT = 'hyva_menu_widget';
    public const FIELD = 'menu_identifier';
    private const LABEL_ID_PATTERN = '/\(ID: (\d+)\)/';

    /**
     * @param ContentScopeFilter $contentScopeFilter
     * @param MenuGatewayInterface $menuGateway
     */
    public function __construct(
        private readonly ContentScopeFilter $contentScopeFilter,
        private readonly MenuGatewayInterface $menuGateway
    ) {
    }

    /**
     * Whether a component field lists menus.
     *
     * @param string $component
     * @param string $field
     * @return bool
     */
    public function isMenuField(string $component, string $field): bool
    {
        return $component === self::COMPONENT && $field === self::FIELD;
    }

    /**
     * Keep the options the caller may see.
     *
     * @param array $options Hyvä option list: value (menu identifier, '' for the placeholder) and label.
     * @return array
     * @phpstan-param array<mixed> $options
     * @phpstan-return array{options: list<mixed>, hidden: int}
     */
    public function filter(array $options): array
    {
        $viewable = $this->contentScopeFilter->viewableTypes([ContentLocator::TYPE_MENU]) !== [];
        if ($viewable && !$this->contentScopeFilter->isRestricted()) {
            return ['options' => array_values($options), 'hidden' => 0];
        }
        $readableIdentifiers = [];
        $readableIds = [];
        if ($viewable) {
            foreach ($this->menuGateway->getList() as $menu) {
                if ($this->contentScopeFilter->allowsStores(Cast::intList($menu['store_ids'] ?? []))) {
                    $readableIdentifiers[Cast::string($menu['identifier'] ?? '')] = true;
                    $readableIds[Cast::int($menu['id'] ?? 0)] = true;
                }
            }
        }
        $visible = [];
        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;
            if ($value === '') {
                $visible[] = $option;
                continue;
            }
            if (!is_string($value) || !isset($readableIdentifiers[$value])) {
                continue;
            }
            $label = is_array($option) && is_string($option['label'] ?? null) ? $option['label'] : '';
            if (preg_match(self::LABEL_ID_PATTERN, $label, $match) !== 1 || isset($readableIds[(int) $match[1]])) {
                $visible[] = $option;
            }
        }
        return ['options' => $visible, 'hidden' => count($options) - count($visible)];
    }
}
