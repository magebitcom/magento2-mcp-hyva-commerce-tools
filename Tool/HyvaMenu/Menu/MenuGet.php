<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaMenu\Menu;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Menu\MenuService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Menu\AbstractMenuTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;

/**
 * `hyva_menu.get`: full menu structure composed by field resolvers.
 */
class MenuGet extends AbstractMenuTool implements ContextualAclAwareInterface
{
    public const TOOL_NAME = 'hyva_menu.get';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_menu_get';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param MenuService $menuService
     * @param ContentLocator $contentLocator
     * @param ResolverPipeline $pipeline
     * @param VersionHistoryAcl $versionHistoryAcl
     * @param ContentFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        MenuService $menuService,
        private readonly ContentLocator $contentLocator,
        private readonly ResolverPipeline $pipeline,
        private readonly VersionHistoryAcl $versionHistoryAcl,
        private readonly array $fieldResolvers = []
    ) {
        parent::__construct($featureChecker, $menuService);
    }

    /**
     * Menu version history permission when `fields` asks for the versions key.
     *
     * @inheritDoc
     */
    public function getContextualAclResources(array $arguments): array
    {
        return $this->versionHistoryAcl->forFields(ContentLocator::TYPE_MENU, $arguments);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä Menu';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $keys = [];
        foreach ($this->fieldResolvers as $resolver) {
            $keys[] = $resolver->getKey();
        }
        return 'Return a menu: settings and store views, header/footer placement, nested item structure (uids for '
            . 'hyva_menu.item.*), every link with its category/product/CMS page target and state, preview URLs, '
            . 'versions and usage. Response keys: ' . implode(', ', $keys) . '. Use fields / exclude to narrow it.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->string('edition', fn (StringBuilder $s) => $s
                ->enum(['draft', 'published'])
                ->description('Structure/links of the draft (default) or the published menu.'))
            ->array('fields', fn (ArrayBuilder $a) => $a->ofStrings())
            ->array('exclude', fn (ArrayBuilder $a) => $a->ofStrings())
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeMenu(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate(ContentLocator::TYPE_MENU, $arguments);
        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            if ($resolver instanceof ContentFieldResolverInterface) {
                $response[$resolver->getKey()] = $resolver->resolve($entity, $arguments);
            }
        }
        return $this->jsonResult($response, ['id' => $entity['id']]);
    }
}
