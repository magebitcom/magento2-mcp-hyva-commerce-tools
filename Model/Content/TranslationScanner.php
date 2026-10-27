<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;

/**
 * Translatable texts of a Hyvä CMS document and their per-store translations.
 *
 * Mirrors the editor's translation panel (liveview-editor translations/handler.phtml): every `content` field
 * declared `translate: true` with a non-empty string value, plus the alt text of image fields and the label of link
 * fields. Translations live inline on the component: `translations[<field>][<store id>] = text`, where <field> is
 * the field name, or "image.alt" / "link.label" for the nested ones.
 *
 * @phpstan-type TextRow array{uid: string, component: string, field: string, text: string,
 *     translations: array<int, string>}
 */
class TranslationScanner
{
    /**
     * Nested texts the editor translates for these field types.
     */
    private const NESTED = ['image' => 'alt', 'link' => 'label'];

    /**
     * @param ComponentTree $componentTree
     */
    public function __construct(
        private readonly ComponentTree $componentTree
    ) {
    }

    /**
     * Every translatable text in the document.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<TextRow>
     */
    public function texts(array $document): array
    {
        $texts = [];
        $this->componentTree->walk($document, function (array $node) use (&$texts): void {
            $component = Cast::string($node['component'] ?? '');
            $uid = Cast::string($node['uid'] ?? '');
            $translations = is_array($node['translations'] ?? null) ? $node['translations'] : [];
            foreach ($this->componentTree->contentFields($component) as $name => $field) {
                $value = $node[$name] ?? null;
                $candidates = [];
                if ($field['translate']) {
                    $candidates[$name] = $value;
                }
                if (isset(self::NESTED[$field['type']]) && is_array($value)) {
                    $key = self::NESTED[$field['type']];
                    $candidates[$name . '.' . $key] = $value[$key] ?? null;
                }
                foreach ($candidates as $fieldKey => $text) {
                    if (!is_string($text) || trim($text) === '') {
                        continue;
                    }
                    $texts[] = [
                        'uid' => $uid,
                        'component' => $component,
                        'field' => (string) $fieldKey,
                        'text' => $text,
                        'translations' => $this->storeTexts($translations[$fieldKey] ?? null),
                    ];
                }
            }
        });
        return $texts;
    }

    /**
     * Coverage per store and the texts still missing a translation.
     *
     * @param array $document
     * @param array $storeIds Target store ids
     * @param int $sampleLimit
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param list<int> $storeIds
     * @phpstan-return array{texts_total: int, stores: array<int, array{translated: int, missing: int}>,
     *     missing_sample: list<array{uid: string, component: string, field: string, text: string,
     *     missing_store_ids: list<int>}>}
     */
    public function status(array $document, array $storeIds, int $sampleLimit = 50): array
    {
        $texts = $this->texts($document);
        /** @var array<int, array{translated: int, missing: int}> $stores */
        $stores = [];
        foreach ($storeIds as $storeId) {
            $stores[$storeId] = ['translated' => 0, 'missing' => 0];
        }
        $sample = [];
        foreach ($texts as $row) {
            $missing = [];
            foreach ($storeIds as $storeId) {
                $done = trim($row['translations'][$storeId] ?? '') !== '';
                $current = $stores[$storeId] ?? ['translated' => 0, 'missing' => 0];
                if ($done) {
                    $stores[$storeId] = ['translated' => $current['translated'] + 1, 'missing' => $current['missing']];
                } else {
                    $stores[$storeId] = ['translated' => $current['translated'], 'missing' => $current['missing'] + 1];
                    $missing[] = $storeId;
                }
            }
            if ($missing !== [] && count($sample) < $sampleLimit) {
                $sample[] = [
                    'uid' => $row['uid'],
                    'component' => $row['component'],
                    'field' => $row['field'],
                    'text' => mb_strimwidth(trim(strip_tags($row['text'])), 0, 120, '…'),
                    'missing_store_ids' => $missing,
                ];
            }
        }
        return ['texts_total' => count($texts), 'stores' => $stores, 'missing_sample' => $sample];
    }

    /**
     * Write translations into the document.
     *
     * @param array $document
     * @param array $translations
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param list<array{uid: string, field: string, store_id: int, translation: string}> $translations
     * @phpstan-return array<string, mixed>
     */
    public function apply(array $document, array $translations): array
    {
        $byUid = [];
        foreach ($translations as $row) {
            $byUid[$row['uid']][] = $row;
        }
        return $this->componentTree->mapNodes($document, static function (array $node) use ($byUid): array {
            $uid = Cast::string($node['uid'] ?? '');
            if (!isset($byUid[$uid])) {
                return $node;
            }
            $existing = is_array($node['translations'] ?? null) ? $node['translations'] : [];
            foreach ($byUid[$uid] as $row) {
                $field = is_array($existing[$row['field']] ?? null) ? $existing[$row['field']] : [];
                $field[(string) $row['store_id']] = $row['translation'];
                $existing[$row['field']] = $field;
            }
            $node['translations'] = $existing;
            return $node;
        });
    }

    /**
     * Store id => text of one field's translations.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function storeTexts(mixed $value): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $storeId => $text) {
            if (is_string($text)) {
                $texts[(int) $storeId] = $text;
            }
        }
        return $texts;
    }
}
