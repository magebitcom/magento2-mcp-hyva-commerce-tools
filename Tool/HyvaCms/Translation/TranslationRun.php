<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Translation;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Tool\Translation\AbstractTranslationTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;

/**
 * @phpstan-type TranslationItem array{fieldId: string, originalText: string, uid: string, field: string,
 *     targetStores: list<array{storeId: int, storeCode: string, translationKey: string}>}
 */
class TranslationRun extends AbstractTranslationTool
{
    public const TOOL_NAME = 'hyva_cms.translation.run';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_translation_run';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];
    public const EXTRA_ACL_RESOURCES = ['Hyva_CmsAiTranslations::translate'];
    public const IS_WRITE = true;

    /**
     * Hyvä TranslationBatcher limits per AI request.
     */
    private const BATCH_TEXTS = 15;
    private const BATCH_CHARS = 30000;

    /**
     * Longest text the editor's Translate panel sends (translation.js MAX_FIELD_SIZE).
     */
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * Hyva_CmsAiTranslations::translate only exists while AI Translations is enabled.
     *
     * @return list<string>
     */
    protected function extraAclResources(): array
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::CMS_AI_TRANSLATIONS)['enabled']
            ? parent::extraAclResources()
            : [];
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Run Hyvä CMS AI Translation';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Translate the texts of a Hyvä CMS page, block or menu into each store view\'s language with the AI '
            . 'model configured in Hyvä AI Translations (same prompt and rules as the editor\'s Translate panel). '
            . 'Returns the proposals; with write_draft=true they are saved into the DRAFT (never published: review '
            . 'with hyva_cms.preview, then hyva_cms.publish). Default: only texts without a translation. Calls a paid '
            . 'AI API.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::ofTypes(ContentLocator::TYPES))
            ->array('store_ids', fn (ArrayBuilder $a) => $a->ofIntegers()->maxItems(50)
                ->description('Only these store views. Default: every store view with a target language.'))
            ->string('mode', fn (StringBuilder $s) => $s->enum(['missing_only', 'all'])
                ->description('missing_only (default) or all (re-translate existing translations too).'))
            ->integer('max_texts', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200)
                ->description('Texts per call (default 50); call again for the rest.'))
            ->boolean('write_draft', fn (BooleanBuilder $b) => $b->description('Save the translations into the '
                . 'draft (needs save permission for the content type). Default false.'))
            ->string('base_version_uid', fn (StringBuilder $s) => $s->description('Optional, with write_draft: '
                . 'refused with status 409 when the draft changed since you read it.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeTranslation(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $writeDraft = $this->boolArg($arguments, 'write_draft');
        if ($writeDraft && !$this->contentAcl->isAllowed($entity['type'], ContentAcl::VERB_SAVE)) {
            throw new AuthorizationException(__('Your admin role may not save %1 drafts; run without write_draft '
                . 'to get the proposals only.', $entity['type']));
        }
        $settings = $this->translations->settings();
        if (!$settings['enabled']) {
            return $this->softError('AI translations are disabled (hyva_ai/hyva_cms_translations/enabled). '
                . 'Nothing was translated.');
        }
        $targets = $this->targetStores($settings, Cast::intList($arguments['store_ids'] ?? []));
        if ($targets === []) {
            return $this->softError('No store view has a target language different from the content language ('
                . $settings['default_language'] . ').');
        }
        $all = ($this->stringArg($arguments, 'mode') ?? 'missing_only') === 'all';
        $document = $this->contentService->getWorkingDocument($entity['type'], $entity['id']);
        $items = [];
        $skipped = [];
        $pending = 0;
        foreach ($this->scanner->texts($document) as $text) {
            $stores = [];
            foreach ($targets as $target) {
                if ($all || trim($text['translations'][$target['store_id']] ?? '') === '') {
                    $fieldId = $text['uid'] . '_' . $text['field'];
                    $stores[] = [
                        'storeId' => $target['store_id'],
                        'storeCode' => $target['code'],
                        'translationKey' => $fieldId . '_' . $target['store_id'],
                    ];
                }
            }
            if ($stores === []) {
                continue;
            }
            $source = $this->promptText($text['text']);
            if ($source === null) {
                $skipped[] = ['uid' => $text['uid'], 'field' => $text['field'], 'reason' => mb_strlen($text['text'])
                    > self::MAX_TEXT_LENGTH ? 'longer than 2000 characters' : 'plain text with line breaks'];
                continue;
            }
            $pending++;
            if (count($items) < ($this->intArg($arguments, 'max_texts', 50) ?? 50)) {
                $items[] = [
                    'fieldId' => $text['uid'] . '_' . $text['field'],
                    'originalText' => $source,
                    'targetStores' => $stores,
                    'uid' => $text['uid'],
                    'field' => $text['field'],
                ];
            }
        }
        if ($items === []) {
            return $this->jsonResult(['translated' => 0, 'skipped' => $skipped, 'note' => 'Nothing to translate: '
                . 'every text already has a translation for the selected store views (pass mode "all" to redo '
                . 'them), or was skipped.'], ['translated' => 0]);
        }
        $result = [];
        try {
            foreach ($this->batches($items) as $batch) {
                $result += $this->translations->translate(array_map(static fn (array $item): array => [
                    'fieldId' => $item['fieldId'],
                    'originalText' => $item['originalText'],
                    'targetStores' => $item['targetStores'],
                ], $batch));
            }
        } catch (LocalizedException $e) {
            return $this->softError('The AI translation failed: ' . $e->getMessage() . ' Nothing was changed.');
        }
        $proposals = [];
        foreach ($items as $item) {
            foreach ($item['targetStores'] as $store) {
                $translation = $result[$store['translationKey']] ?? null;
                if ($translation !== null && trim($translation) !== '') {
                    $proposals[] = [
                        'uid' => $item['uid'],
                        'field' => $item['field'],
                        'store_id' => $store['storeId'],
                        'source' => $item['originalText'],
                        'translation' => $translation,
                    ];
                }
            }
        }
        $payload = [
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'translated' => count($proposals),
            'texts_remaining' => $pending - count($items),
            'proposals' => $proposals,
            'skipped' => $skipped,
            'draft_saved' => false,
        ];
        if ($writeDraft && $proposals !== []) {
            $this->contentService->saveDraft(
                $entity['type'],
                $entity['id'],
                $this->scanner->apply($document, $proposals),
                'AI translations',
                // The AI call is slow: refuse (409) rather than overwrite a draft someone saved meanwhile.
                $this->stringArg($arguments, 'base_version_uid') ?? $this->versionUid($document)
            );
            $payload['draft_saved'] = true;
            $payload['next_step'] = 'Review with hyva_cms.preview, then publish with hyva_cms.publish.';
        }
        return $this->jsonResult($payload, [
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'translated' => count($proposals),
            'draft_saved' => $payload['draft_saved'],
        ]);
    }

    /**
     * Split texts the way Hyvä's TranslationBatcher does.
     *
     * At most 15 texts and 30,000 characters (text length times target stores) per AI request.
     *
     * @param array $items
     * @return array
     * @phpstan-param list<TranslationItem> $items
     * @phpstan-return list<list<TranslationItem>>
     */
    private function batches(array $items): array
    {
        $batches = [];
        $current = [];
        $size = 0;
        foreach ($items as $item) {
            $itemSize = strlen($item['originalText']) * max(1, count($item['targetStores']));
            if ($current !== [] && (count($current) >= self::BATCH_TEXTS || $size + $itemSize > self::BATCH_CHARS)) {
                $batches[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = $item;
            $size += $itemSize;
        }
        if ($current !== []) {
            $batches[] = $current;
        }
        return $batches;
    }

    /**
     * Version uid of the document read before the AI call.
     *
     * @param array $document
     * @return string|null
     * @phpstan-param array<string, mixed> $document
     */
    private function versionUid(array $document): ?string
    {
        $uid = is_array($document['version'] ?? null) ? ($document['version']['uid'] ?? null) : null;
        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * Text as it can go into Hyvä's prompt, or null to skip it.
     *
     * Hyvä joins the texts of a request with line breaks and splits the answer on line breaks, so a text with a
     * line break would shift every later translation onto the wrong field. Line breaks in HTML render as spaces
     * and are collapsed; plain text with line breaks, and texts the editor would not send either, are skipped.
     *
     * @param string $text
     * @return string|null
     */
    private function promptText(string $text): ?string
    {
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return null;
        }
        if (!preg_match('/[\r\n]/', $text)) {
            return $text;
        }
        if ($text === strip_tags($text) || preg_match('/<(pre|textarea)\b/i', $text)) {
            return null;
        }
        return trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $text));
    }
}
