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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magebit\McpHyvaCommerceTools\Model\Tool\Translation\AbstractTranslationTool;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

class TranslationStatus extends AbstractTranslationTool
{
    public const TOOL_NAME = 'hyva_cms.translation.status';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_cms_translation_status';
    public const CONTENT_VERBS = [ContentAcl::VERB_VIEW];

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Hyvä CMS Translation Status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Show how much of a Hyvä CMS page, block or menu is translated for each store view with its own '
            . 'language (Hyvä AI Translations settings), and which texts still miss a translation. Reads the working '
            . 'copy (draft if there is one). Translations are stored inside the content; '
            . 'hyva_cms.translation.run fills the gaps.';
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
            ->integer('limit', fn (IntegerBuilder $i) => $i->minimum(0)->maximum(200)
                ->description('Missing texts to list. Default 50.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeTranslation(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate($this->requireString($arguments, 'entity_type'), $arguments);
        $settings = $this->translations->settings();
        $targets = $this->targetStores($settings, Cast::intList($arguments['store_ids'] ?? []));
        $document = $this->contentService->getWorkingDocument($entity['type'], $entity['id']);
        $status = $this->scanner->status(
            $document,
            array_column($targets, 'store_id'),
            $this->intArg($arguments, 'limit', 50) ?? 50
        );
        $stores = [];
        foreach ($targets as $target) {
            $stores[] = $target + $status['stores'][$target['store_id']];
        }
        $notes = [];
        if (!$settings['enabled']) {
            $notes[] = 'AI translations are disabled (hyva_ai/hyva_cms_translations/enabled); '
                . 'hyva_cms.translation.run will refuse until they are on.';
        }
        if ($targets === []) {
            $notes[] = 'No store view has a target language different from the content language ('
                . $settings['default_language'] . '), so there is nothing to translate.';
        }
        return $this->jsonResult([
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'content_language' => $settings['default_language'],
            'ai_enabled' => $settings['enabled'],
            'model' => $settings['model'],
            'texts_total' => $status['texts_total'],
            'stores' => $stores,
            'missing_sample' => $status['missing_sample'],
            'notes' => $notes,
        ], ['entity_type' => $entity['type'], 'id' => $entity['id'], 'texts_total' => $status['texts_total']]);
    }
}
