<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Hyva\CmsConnect\Exception\ConflictException;
use Magento\Framework\Exception\LocalizedException;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\LiveSettings;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\ContentSchema;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\AuthorizationException;

/**
 * Shared implementation of hyva_cms.page.update / hyva_cms.block.update.
 * Content changes are saved as a draft; Magento settings (`settings`) apply immediately, so those that change the
 * live storefront need `confirm: true` (without it nothing is written and a preview comes back).
 */
abstract class AbstractEntityUpdate extends AbstractTool
{
    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';
    public const IS_WRITE = true;

    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ComponentTree $componentTree
     * @param DraftEditor $draftEditor
     * @param EntityWriter $entityWriter
     * @param LiveSettings $liveSettings
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentService $contentService,
        private readonly ComponentTree $componentTree,
        private readonly DraftEditor $draftEditor,
        private readonly EntityWriter $entityWriter,
        private readonly LiveSettings $liveSettings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Update Hyvä CMS ' . static::ENTITY_LABEL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Update a CMS %1$s. Content changes (components = replace all root components, operations = targeted '
                . 'edits) are saved as a DRAFT and the live version is unchanged until hyva_cms_publish. "settings" '
                . 'changes '
                . 'Magento fields (title, active flag, store views, meta) and applies IMMEDIATELY: a setting that '
                . 'changes the live storefront (active flag, store views, URL key; any field of an active %1$s) needs '
                . '"confirm": true. Without it nothing is written and the reply lists what would change (dry_run: '
                . 'true); any other call writes at once and says dry_run: false. '
                . sprintf(self::DELETE_TOOL_NOTE, 'a CMS %1$s', 'hyva_cms_%1$s_delete'),
            strtolower(static::ENTITY_LABEL)
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $allowed = static::ENTITY_TYPE === ContentLocator::TYPE_PAGE
            ? EntityWriter::PAGE_FIELDS
            : EntityWriter::BLOCK_FIELDS;
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->rawProperty('components', ContentSchema::COMPONENTS)
            ->rawProperty('operations', ContentSchema::OPERATIONS)
            ->object('settings', function (ObjectBuilder $o) use ($allowed): void {
                foreach ($allowed as $field) {
                    if ($field === 'store_ids') {
                        // As in the admin form a store view is required: an empty list takes the entity offline.
                        $o->rawProperty('store_ids', ['type' => 'array', 'items' => ['type' => 'integer'],
                            'minItems' => 1]);
                    } elseif ($field === 'is_active') {
                        $o->rawProperty('is_active', ['type' => 'boolean']);
                    } else {
                        $o->string($field, fn (StringBuilder $s) => $s);
                    }
                }
                $o->description('Magento fields, applied immediately: ' . implode(', ', $allowed) . '. Live changes '
                    . 'need confirm.');
            })
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description('Apply settings that change the live '
                . 'storefront, after reviewing the preview.'))
            ->string('version_name', fn (StringBuilder $s) => $s
                ->maxLength(100)
                ->description('Label for the version-history entry.'))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description(
                'Optional: draft version uid you read (draft.version.uid, or version_uid of your last write). The '
                . 'save is refused (status 409) when the draft changed since you read it.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $components = $this->arrayArg($arguments, 'components');
        $operations = $this->arrayArg($arguments, 'operations');
        $settings = $this->arrayArg($arguments, 'settings');
        $newStoreIds = null;
        if (array_key_exists('store_ids', $settings) && $settings['store_ids'] !== null) {
            $storeIds = $settings['store_ids'];
            $newStoreIds = Cast::intList(is_array($storeIds) ? $storeIds : [$storeIds]);
        }
        // A re-assigning call is judged by the store views it enters (Hyvä admin rule, see StoreScopeGuard).
        $entity = $this->contentLocator->locateForWrite(static::ENTITY_TYPE, $arguments, $newStoreIds);
        if ($components === [] && $operations === [] && $settings === []) {
            return $this->softError('Nothing to update: pass components, operations and/or settings.');
        }

        // Layout, heading and meta are not part of the located row: load them so an unchanged value is no change.
        $current = $entity + $this->entityWriter->currentValues(
            $entity['type'],
            $entity['id'],
            array_map('strval', array_keys(array_diff_key($settings, $entity)))
        );
        $live = $this->liveSettings->changes($current, $settings);
        if ($live !== [] && !$this->boolArg($arguments, 'confirm')) {
            // Nothing is written, the draft half of the call included: one call, one decision.
            return $this->confirmationRequired('apply_live_settings', $live);
        }

        // A write reply says it wrote (T-152): only settings that change the live storefront need confirm.
        $result = ['dry_run' => false, 'entity_type' => static::ENTITY_TYPE, 'id' => $entity['id']];
        $applied = null;
        if ($components !== [] || $operations !== []) {
            $document = $this->contentService->getWorkingDocument($entity['type'], $entity['id']);
            if ($components !== []) {
                $document['content'] = [];
                foreach ($this->draftEditor->buildNodes($components) as $node) {
                    $document = $this->componentTree->insertNode($document, $node);
                }
            }
            $applied = $this->draftEditor->apply($document, $operations);
        }
        $stringKeyed = [];
        foreach ($settings as $key => $value) {
            $stringKeyed[(string) $key] = $value;
        }

        if ($this->contentLocator->changesOnlyAfterReassignment($entity, $newStoreIds)) {
            // Allowed only because it moves the entity into the caller's store views: move it first, so a refused
            // move writes nothing and the draft never lands on an entity the caller may not change where it is.
            // A draft refusal that is known before the move (stale base_version_uid, invalid content) writes nothing.
            if ($applied !== null) {
                $this->contentService->assertDraftSavable(
                    $entity['type'],
                    $entity['id'],
                    $applied['document'],
                    $this->stringArg($arguments, 'base_version_uid')
                );
            }
            $result['settings_changed'] = $this->entityWriter->update($entity['type'], $entity['id'], $stringKeyed);
            if ($applied !== null) {
                try {
                    $result += $this->saveDraft($entity, $applied, $arguments);
                } catch (LocalizedException|ConflictException $exception) {
                    return $this->draftRefusedAfterSettings($exception, $result);
                }
            }
        } else {
            // The draft goes first: it is not live and stays in version history, whereas settings change the live
            // entity, so a refused draft (stale base_version_uid, bad operations) must not leave settings applied.
            if ($applied !== null) {
                $result += $this->saveDraft($entity, $applied, $arguments);
            }
            if ($stringKeyed !== []) {
                try {
                    $result['settings_changed'] = $this->entityWriter->update(
                        $entity['type'],
                        $entity['id'],
                        $stringKeyed
                    );
                } catch (LocalizedException $exception) {
                    if (!isset($result['draft'])) {
                        throw $exception;
                    }
                    // The draft is already saved: report it with the error instead of hiding a partial write.
                    return $this->softError(
                        'The draft was saved but the settings were not applied: ' . $exception->getMessage(),
                        ['draft_saved' => true, 'settings_applied' => false]
                            + ($exception instanceof AuthorizationException ? $this->refusalData($exception) : [])
                            + $result
                    );
                }
            }
        }

        return $this->jsonResult($result, [
            'entity_type' => static::ENTITY_TYPE,
            'id' => $entity['id'],
            'operations' => count($operations),
            'settings' => array_keys($settings),
        ]);
    }

    /**
     * Save the edited document as the entity's draft
     *
     * @param array $entity
     * @param array $applied Output of DraftEditor::apply().
     * @param array $arguments
     * @return array
     * @throws LocalizedException
     * @throws ConflictException When the draft changed since base_version_uid.
     * @phpstan-param array{type: string, id: int} $entity
     * @phpstan-param array{document: array<string, mixed>, applied: list<array<string, mixed>>} $applied
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array{draft: array{version_id: int|null, version_uid: string,
     *     warnings: list<array<string, mixed>>}, applied_operations: list<array<string, mixed>>, published: false}
     */
    private function saveDraft(array $entity, array $applied, array $arguments): array
    {
        return [
            'draft' => $this->contentService->saveDraft(
                $entity['type'],
                $entity['id'],
                $applied['document'],
                $this->stringArg($arguments, 'version_name'),
                $this->stringArg($arguments, 'base_version_uid')
            ),
            'applied_operations' => $applied['applied'],
            'published' => false,
        ];
    }
}
