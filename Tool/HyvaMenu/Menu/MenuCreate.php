<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuItemBuilder;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_menu.create` MCP tool.
 */
class MenuCreate extends AbstractMenuTool
{
    public const TOOL_NAME = 'hyva_menu.create';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_create';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_MenuBuilder::menu_save';
    public const IS_WRITE = true;

    public const TEMPLATES = [
        'header' => ['desktop_menu_columns', 'mobile_menu'],
        'drilldown' => ['desktop_menu_drilldown', 'mobile_menu'],
        'mobile' => ['mobile_menu'],
        'footer' => ['menu_footer_columns'],
        'empty' => [],
    ];

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param MenuGatewayInterface $menuGateway
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param MenuItemBuilder $menuItemBuilder
     * @param DraftEditor $draftEditor
     * @param PreviewService $previewService
     * @param StoreScopeGuard $storeScopeGuard
     * @param LiveSettings $liveSettings
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly MenuGatewayInterface $menuGateway,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly MenuItemBuilder $menuItemBuilder,
        private readonly DraftEditor $draftEditor,
        private readonly PreviewService $previewService,
        private readonly StoreScopeGuard $storeScopeGuard,
        private readonly LiveSettings $liveSettings
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Create Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a menu with its first content saved as a DRAFT (nothing renders until hyva_menu_publish). '
            . 'The menu is inactive unless is_active=true; hyva_menu_publish also activates it. is_active=true '
            . 'needs "confirm": true; without it nothing is created and the reply shows what would go live. '
            . 'template picks the '
            . 'root components: header (mega menu columns + mobile), drilldown (drilldown + mobile), mobile, footer '
            . '(footer columns) or empty. items are added to every root '
            . '({label, type: category|product|cms_page|custom_url|text|category_tree|content, value, children}).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('title', fn (StringBuilder $s) => $s->minLength(2)->required())
            ->string('identifier', fn (StringBuilder $s) => $s->pattern('^[a-z0-9_]+$')->minLength(2)->required())
            ->array('store_ids', fn (ArrayBuilder $a) => $a
                ->ofIntegers()
                ->minItems(1)
                ->required()
                ->description('0 = all store views.'))
            ->boolean('is_active', fn (BooleanBuilder $b) => $b
                ->description('Default false. true makes the menu active at once and needs confirm.'))
            ->string('preview_url_key', fn (StringBuilder $s) => $s
                ->description('URL key of a page that shows this menu (the preview fails on a page without it). '
                    . 'It is also the page the published_url points to.'))
            ->string('template', fn (StringBuilder $s) => $s
                ->enum(array_keys(self::TEMPLATES))
                ->description('Default header.'))
            ->rawProperty(
                'items',
                [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                    'description' => 'Menu item specs added to every root component.',
                ]
            )
            ->rawProperty('components', ContentSchema::COMPONENTS)
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Create with is_active=true, after '
                . 'reviewing the preview.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $this->storeScopeGuard->assertAssignable('the new menu', [], $this->intListArg($arguments, 'store_ids'));
        $document = $this->componentTree->newDocument(ContentLocator::TYPE_MENU, 0);
        $components = $this->arrayArg($arguments, 'components');
        $roots = $components !== []
            ? $this->draftEditor->buildNodes($components)
            : array_map(
                fn (string $c): array => $this->componentTree->createNode($c),
                self::TEMPLATES[$this->stringArg($arguments, 'template', 'header') ?? 'header'] ?? []
            );
        foreach ($roots as $root) {
            $children = is_array($root['children'] ?? null) ? array_values($root['children']) : [];
            foreach ($this->arrayArg($arguments, 'items') as $spec) {
                if (is_array($spec)) {
                    $children[] = $this->menuItemBuilder->build(Cast::string($root['component'] ?? ''), $spec);
                }
            }
            $root['children'] = $children;
            $document = $this->componentTree->insertNode($document, $root);
        }
        $validation = $this->contentService->validate($document);
        if (!$validation['valid']) {
            return $this->softError('Nothing was created: the menu content is invalid.', $validation);
        }

        $isActive = $this->boolArg($arguments, 'is_active');
        $live = $this->liveSettings->changes(['is_active' => false], ['is_active' => $isActive]);
        foreach ($live as $i => $change) {
            // An active menu renders nothing until it is published (T-134).
            $live[$i]['effect'] = 'Goes live on the storefront once it is published (hyva_menu_publish) and placed '
                . '(design config header/footer); an active, unpublished menu renders nothing.';
        }
        if ($live !== [] && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('create_active', [[
                'entity_type' => ContentLocator::TYPE_MENU,
                'identifier' => $this->requireString($arguments, 'identifier'),
                'title' => $this->requireString($arguments, 'title'),
                'store_ids' => $this->intListArg($arguments, 'store_ids'),
                'components' => count($roots),
                'changes' => $live,
            ]]);
        }
        $menuId = $this->menuGateway->create([
            'title' => $this->requireString($arguments, 'title'),
            'identifier' => $this->requireString($arguments, 'identifier'),
            'store_ids' => $this->intListArg($arguments, 'store_ids'),
            'is_active' => $isActive,
            'preview_url_key' => $this->stringArg($arguments, 'preview_url_key'),
        ]);
        $document = $this->componentTree->normalizeDocument($document, ContentLocator::TYPE_MENU, $menuId);
        $saved = $this->contentService->saveDraft(ContentLocator::TYPE_MENU, $menuId, $document, 'Created by MCP');

        return $this->jsonResult([
            'created' => true,
            'id' => $menuId,
            'draft' => $saved,
            'structure' => $this->menuService->describe($document),
            'is_active' => $isActive,
            'published' => false,
            'preview' => $this->previewService->getPreview(ContentLocator::TYPE_MENU, $menuId),
            'next_step' => 'Publish with hyva_menu_publish (it also activates the menu) and assign it in design '
                . 'config (header/footer) to show it.',
        ], ['id' => $menuId, 'roots' => count($roots)]);
    }
}
