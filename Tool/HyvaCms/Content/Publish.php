<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Content;

use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_cms.publish`: the only tool that makes Hyvä CMS content live.
 *
 * Hyvä Menu Builder activates a menu on publish, so publishing an inactive menu needs "confirm": true, as creating
 * an active one does (T-165); without it the reply previews the activation.
 */
class Publish extends AbstractContentTool
{
    public const TOOL_NAME = 'hyva_cms.publish';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_publish';
    public const IS_WRITE = true;
    /**
     * Tool that sets the active flag of each content type (wire names).
     */
    private const UPDATE_TOOLS = [
        ContentLocator::TYPE_PAGE => 'hyva_cms_page_update',
        ContentLocator::TYPE_BLOCK => 'hyva_cms_block_update',
        ContentLocator::TYPE_MENU => 'hyva_menu_update',
    ];

    public const CONTENT_VERBS = [ContentAcl::VERB_PUBLISH];

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param ContentAcl $contentAcl
     * @param LiveSettings $liveSettings
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly ContentAcl $contentAcl,
        private readonly LiveSettings $liveSettings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Publish Hyvä CMS Content';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Make content LIVE on the storefront: publish the current draft of a page, block or menu, or a '
            . 'specific version_id from version history. Also enables Hyvä CMS rendering for the entity and cleans '
            . 'its cache. The previous live version stays in version history. Publishing an inactive menu also '
            . 'activates it and needs "confirm": true; without it nothing is published and the reply shows what '
            . 'would go live.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->integer('version_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Publish this version instead of the current draft.'))
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Publish an inactive menu, which '
                . 'activates it, after reviewing the preview.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $type = $this->requireString($arguments, 'entity_type');
        $entity = $this->contentLocator->locateForWrite($type, $arguments);
        $before = $this->contentService->getDocuments($entity['type'], $entity['id'])['published'];
        $live = $entity['type'] === ContentLocator::TYPE_MENU
            ? $this->liveSettings->changes(['is_active' => $entity['is_active']], ['is_active' => true])
            : [];
        if ($live !== [] && !$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired('publish_activate', [[
                'entity_type' => $entity['type'],
                'id' => $entity['id'],
                'identifier' => $entity['identifier'],
                'title' => $entity['title'],
                'store_ids' => $entity['store_ids'],
                'version_id' => $this->intArg($arguments, 'version_id'),
                'changes' => $live,
            ]], [], null, 'Hyvä Menu Builder activates a menu when it publishes it; the storefront shows it where '
                . LiveSettings::MENU_PLACEMENT . ' places it.');
        }
        $result = $this->contentService->publish(
            $entity['type'],
            $entity['id'],
            $this->intArg($arguments, 'version_id')
        );
        $after = $this->contentService->getDocuments($entity['type'], $entity['id'])['published'];
        // Hyvä Menu Builder activates a menu on publish (MenuProvider::saveContent()), so the flag is read again.
        $isActive = $this->contentLocator->locate($entity['type'], ['id' => $entity['id']])['is_active'];

        return $this->jsonResult([
            'published' => true,
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'identifier' => $entity['identifier'],
            'is_active' => $isActive,
            'result' => $result,
            'components_before' => $before !== null ? $this->componentTree->countComponents($before) : [],
            'components_after' => $after !== null ? $this->componentTree->countComponents($after) : [],
            'note' => $isActive
                ? null
                : 'The Magento entity is inactive, so the content is not visible yet. Activate it with '
                    . (self::UPDATE_TOOLS[$entity['type']] ?? 'its update tool')
                    . ' {"settings": {"is_active": true}, "confirm": true}.',
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'source' => $result['source']]);
    }

    /**
     * Publish; publishing a version_id also reads version history.
     *
     * @inheritDoc
     */
    protected function contentVerbs(array $arguments): array
    {
        $verbs = parent::contentVerbs($arguments);
        if (($arguments['version_id'] ?? null) !== null) {
            $verbs[] = ContentAcl::VERB_VERSION_HISTORY;
        }
        return $verbs;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
