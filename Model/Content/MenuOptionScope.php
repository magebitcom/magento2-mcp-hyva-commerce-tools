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
use Magento\Framework\Escaper;

/**
 * Menu options of the hyva_menu_widget component, cut to the menus the caller may see (T-148).
 *
 * Same rule as hyva_menu_list and the schedule tools: no menu for a caller who may not view menus
 * (ContentScopeFilter::viewableTypes()), and for a restricted caller only menus of store views it may read. Hyvä keys
 * the options by menu identifier, which several store views can share, and names the menu id in the label
 * ("Title (ID: 3)"): an option stays when the menu its label names is readable, or, for a label without an id (e.g.
 * translated), when one menu with its identifier is. The empty placeholder option always stays.
 *
 * The label starts with the admin title, which can itself hold "(ID: N)": the menu named is the one whose identifier
 * is the option value and whose title + " (ID: id)" starts the label. When no menu or several qualify, the option is
 * hidden.
 */
class MenuOptionScope
{
    public const COMPONENT = 'hyva_menu_widget';
    public const FIELD = 'menu_identifier';
    private const LABEL_ID_PATTERN = '/\(ID: (\d+)\)/';

    /**
     * @param ContentScopeFilter $contentScopeFilter
     * @param MenuGatewayInterface $menuGateway
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly ContentScopeFilter $contentScopeFilter,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly Escaper $escaper
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
        $menus = [];
        if ($viewable) {
            foreach ($this->menuGateway->getList() as $menu) {
                $identifier = Cast::string($menu['identifier'] ?? '');
                $readable = $this->contentScopeFilter->allowsStores(Cast::intList($menu['store_ids'] ?? []));
                $menus[Cast::int($menu['id'] ?? 0)] = ['identifier' => $identifier,
                    'title' => Cast::string($menu['title'] ?? ''), 'readable' => $readable];
                if ($readable) {
                    $readableIdentifiers[$identifier] = true;
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
            if ($this->labelAllows($label, $value, $menus)) {
                $visible[] = $option;
            }
        }
        return ['options' => $visible, 'hidden' => count($options) - count($visible)];
    }

    /**
     * Whether the menu the label names is readable; a label without "(ID: N)" was already checked by identifier.
     *
     * @param string $label
     * @param string $identifier Option value.
     * @param array $menus
     * @return bool
     * @phpstan-param array<int, array{identifier: string, title: string, readable: bool}> $menus
     */
    private function labelAllows(string $label, string $identifier, array $menus): bool
    {
        if (preg_match_all(self::LABEL_ID_PATTERN, $label, $matches) < 1) {
            return true;
        }
        $named = [];
        foreach (array_unique(array_map('intval', $matches[1])) as $id) {
            $menu = $menus[$id] ?? null;
            if ($menu !== null && $menu['identifier'] === $identifier
                && $this->startsWithTitle($label, $menu['title'], $id)
            ) {
                $named[] = $menu;
            }
        }
        return count($named) === 1 && $named[0]['readable'];
    }

    /**
     * Whether the label starts with "<title> (ID: <id>)"; Hyvä escapes the label with escapeHtml(), so both count.
     *
     * @param string $label
     * @param string $title
     * @param int $id
     * @return bool
     */
    private function startsWithTitle(string $label, string $title, int $id): bool
    {
        $suffix = ' (ID: ' . $id . ')';
        return str_starts_with($label, $title . $suffix)
            || str_starts_with($label, Cast::string($this->escaper->escapeHtml($title)) . $suffix);
    }
}
