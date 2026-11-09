<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\FormBuilder\Block\Widget\Form as FormWidget;
use Hyva\MenuBuilder\Block\Widget\Menu as MenuWidget;
use Magento\Cms\Block\Widget\Block as BlockWidget;
use Magento\Framework\App\ResourceConnection;

/**
 * Finds where CMS blocks, menus and forms are referenced: Hyvä CMS components, Magento CMS content
 * (widget directives) and widget instances.
 */
class UsageFinder
{
    /**
     * Hyvä hyva_menu_widget nodes, scanned once per request.
     *
     * @var array|null
     * @phpstan-var list<array<string, mixed>>|null
     */
    private ?array $menuWidgetNodes = null;

    public const MENU_WIDGET_CLASS = MenuWidget::class;
    public const FORM_WIDGET_CLASS = FormWidget::class;
    private const BLOCK_WIDGET_CLASS = BlockWidget::class;

    /**
     * @param ContentScanner $contentScanner
     * @param ResourceConnection $resourceConnection
     * @param EntityPickerReference $entityPicker
     */
    public function __construct(
        private readonly ContentScanner $contentScanner,
        private readonly ResourceConnection $resourceConnection,
        private readonly EntityPickerReference $entityPicker
    ) {
    }

    /**
     * Find block usage.
     *
     * The cms_block component's block_identifier is an entity picker value (object, list or legacy string) whose id
     * is the block id, see EntityPickerReference. Form Builder forms (draft and published) name a block the same way
     * in success_content_block (form root metadata); those usages are listed in hyva_content with entity_type form
     * and the form's store_ids, whatever the form's success_content_type, as switching it back shows the block again.
     *
     * @param int $blockId
     * @param string $identifier
     * @param bool $ignoreMenuAcl Also scan menus the caller may not view (in-use decisions), see ContentScanner.
     * @return array
     * @phpstan-return array{hyva_content: list<array<string, mixed>>, magento_content: list<array<string, mixed>>,
     *     widget_instances: list<array<string, mixed>>, total: int}
     */
    public function findBlockUsage(int $blockId, string $identifier, bool $ignoreMenuAcl = false): array
    {
        $refs = array_values(array_filter([(string) $blockId, $identifier], static fn (string $v): bool => $v !== ''));
        $picker = $this->entityPicker;
        $hyva = $this->contentScanner->findNodes(static function (array $node) use ($refs, $picker): bool {
            $component = $node['component'] ?? null;
            if ($component === 'cms_block') {
                return $picker->references($node['block_identifier'] ?? null, 'cms_block', $refs);
            }
            if ($component === 'widget') {
                $encoded = (string) json_encode($node['widget'] ?? null);
                foreach ($refs as $ref) {
                    if (str_contains(
                        $encoded,
                        'block_id=\\"' . $ref . '\\"'
                    ) || str_contains($encoded, '"block_id":"' . $ref . '"')
                    ) {
                        return true;
                    }
                }
            }
            return false;
        }, ContentLocator::TYPES, $ignoreMenuAcl);
        $forms = $this->contentScanner->findFormNodes(
            static fn (array $node): bool => array_key_exists('success_content_block', $node)
                && $picker->references($node['success_content_block'], 'cms_block', $refs)
        );
        array_push($hyva, ...$forms);

        $magento = [];
        foreach ($refs as $ref) {
            foreach ($this->contentScanner->findInMagentoContent('block_id="' . $ref . '"') as $match) {
                $magento[$match['entity_type'] . ':' . $match['id']] = $match;
            }
        }

        $widgets = $this->findWidgetInstances(self::BLOCK_WIDGET_CLASS, 'block_id', $refs);
        $magento = array_values($magento);
        return [
            'hyva_content' => $hyva,
            'magento_content' => $magento,
            'widget_instances' => $widgets,
            'total' => count($hyva) + count($magento) + count($widgets),
        ];
    }

    /**
     * Find menu references.
     *
     * @param string $identifier
     * @return array
     * @phpstan-return array{hyva_content: list<array<string, mixed>>, magento_content: list<array<string, mixed>>,
     *     widget_instances: list<array<string, mixed>>}
     */
    public function findMenuReferences(string $identifier): array
    {
        if ($this->menuWidgetNodes === null) {
            $this->menuWidgetNodes = [];
            $nodes = $this->contentScanner->findNodes(
                static fn (array $node): bool => ($node['component'] ?? null) === 'hyva_menu_widget'
            );
            foreach ($nodes as $match) {
                $this->menuWidgetNodes[] = $match;
            }
        }
        $hyva = array_values(array_filter(
            $this->menuWidgetNodes,
            static fn (array $match): bool => ($match['menu_identifier'] ?? null) === $identifier
        ));
        return [
            'hyva_content' => $hyva,
            'magento_content' => $this->contentScanner->findInMagentoContent('menu_identifier="' . $identifier . '"'),
            'widget_instances' => $this->findWidgetInstances(self::MENU_WIDGET_CLASS, 'menu_identifier', [$identifier]),
        ];
    }

    /**
     * Find form references: Hyvä form widgets, widget directives in Magento content and widget instances
     *
     * Hyvä renders a form by identifier in the store view of the page, so every reference to the identifier counts.
     * The widget's form_identifier is an entity picker value (object, list or legacy string), see
     * EntityPickerReference.
     * Menus are scanned whatever the caller's menu permission (in-use decisions), see ContentScanner.
     *
     * @param string $identifier
     * @return array
     * @phpstan-return array{hyva_content: list<array<string, mixed>>, magento_content: list<array<string, mixed>>,
     *     widget_instances: list<array<string, mixed>>, total: int}
     */
    public function findFormReferences(string $identifier): array
    {
        $picker = $this->entityPicker;
        $hyva = $this->contentScanner->findNodes(static function (array $node) use ($identifier, $picker): bool {
            $component = $node['component'] ?? null;
            if ($component === 'hyva_form_widget') {
                return $picker->references($node['form_identifier'] ?? null, 'form', [$identifier]);
            }
            if ($component === 'widget') {
                $encoded = (string) json_encode($node['widget'] ?? null);
                return str_contains($encoded, 'form_identifier=\\"' . $identifier . '\\"')
                    || str_contains($encoded, '"form_identifier":"' . $identifier . '"');
            }
            return false;
        }, ContentLocator::TYPES, true);
        $magento = $this->contentScanner->findInMagentoContent('form_identifier="' . $identifier . '"');
        $widgets = $this->findWidgetInstances(self::FORM_WIDGET_CLASS, 'form_identifier', [$identifier]);
        return [
            'hyva_content' => $hyva,
            'magento_content' => $magento,
            'widget_instances' => $widgets,
            'total' => count($hyva) + count($magento) + count($widgets),
        ];
    }

    /**
     * Find widget instances.
     *
     * @param string $instanceType
     * @param string $parameter
     * @param array $values
     * @return array
     * @phpstan-param array<string> $values
     * @phpstan-return list<array<string, mixed>>
     */
    private function findWidgetInstances(string $instanceType, string $parameter, array $values): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('widget_instance'),
                ['instance_id', 'title', 'theme_id', 'widget_parameters', 'store_ids']
            )
            ->where('instance_type = ?', $instanceType);
        $matches = [];
        foreach ($connection->fetchAll($select) as $row) {
            $params = json_decode((string) $row['widget_parameters'], true);
            $value = is_array($params) ? ($params[$parameter] ?? null) : null;
            if (is_scalar($value) && in_array((string) $value, $values, true)) {
                $matches[] = [
                    'instance_id' => (int) $row['instance_id'],
                    'title' => (string) $row['title'],
                    'theme_id' => (int) $row['theme_id'],
                    'store_ids' => (string) $row['store_ids'],
                ];
            }
        }
        return $matches;
    }
}
