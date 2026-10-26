<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magento\Framework\Exception\LocalizedException;

/**
 * Summaries and field schemas of registered Hyvä CMS components, shaped for AI clients.
 */
class ComponentCatalog
{
    /**
     * @param ComponentProvider $componentProvider
     * @param ComponentTree $componentTree
     * @param FieldValueFormat $fieldValueFormat
     */
    public function __construct(
        private readonly ComponentProvider $componentProvider,
        private readonly ComponentTree $componentTree,
        private readonly FieldValueFormat $fieldValueFormat
    ) {
    }

    /**
     * Get summaries.
     *
     * @param string|null $category
     * @param string|null $search
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    public function getSummaries(?string $category = null, ?string $search = null): array
    {
        $summaries = [];
        foreach ($this->componentProvider->getComponents() as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $summary = $this->summarize((string) $name, $definition);
            if ($category !== null && strcasecmp(Cast::string($summary['category']), $category) !== 0) {
                continue;
            }
            if ($search !== null && stripos(
                $summary['name'] . ' ' . $summary['label'] . ' ' . $summary['description'],
                $search
            ) === false
            ) {
                continue;
            }
            $summaries[] = $summary;
        }
        return $summaries;
    }

    /**
     * Get schema.
     *
     * @param string $name
     * @return array
     * @throws LocalizedException
     * @phpstan-return array<string, mixed>
     */
    public function getSchema(string $name): array
    {
        $definition = $this->componentProvider->getComponent($name);
        if (!is_array($definition)) {
            throw new LocalizedException(__(
                'Component "%1" is %2.',
                $name,
                $this->componentProvider->isComponentDisabled($name) ? 'disabled' : 'not registered'
            ));
        }
        $fields = [];
        foreach (['content', 'design', 'advanced'] as $group) {
            foreach (is_array($definition[$group] ?? null) ? $definition[$group] : [] as $field => $fieldDefinition) {
                if (!is_array($fieldDefinition) || !isset($fieldDefinition['type'])) {
                    continue;
                }
                $attributes = is_array($fieldDefinition['attributes'] ?? null) ? $fieldDefinition['attributes'] : [];
                $options = $fieldDefinition['options'] ?? null;
                $fields[] = array_filter([
                    'name' => (string) $field,
                    'group' => $group,
                    'type' => $fieldDefinition['type'],
                    'label' => $fieldDefinition['label'] ?? null,
                    'required' => !empty($attributes['required']),
                    'default_value' => $fieldDefinition['default_value'] ?? null,
                    'options' => is_array($options) ? $options : null,
                    'options_source' => is_string($options) ? $options : null,
                    'translate' => !empty($fieldDefinition['translate']),
                    'validation' => array_intersect_key(
                        $attributes,
                        array_flip(['min', 'max', 'minlength', 'maxlength', 'pattern', 'step'])
                    ),
                    'comment' => $attributes['comment'] ?? null,
                    'value_format' => $this->fieldValueFormat->get((string) $fieldDefinition['type']),
                ], static fn ($value): bool => $value !== null && $value !== []);
            }
        }
        $summary = $this->summarize($name, $definition);
        return $summary + [
            'fields' => $fields,
            'example_node' => $this->componentTree->createNode($name),
        ];
    }

    /**
     * Summarize.
     *
     * @param string $name
     * @param array $definition
     * @return array
     * @phpstan-param array<mixed> $definition
     * @phpstan-return array<string, mixed>
     */
    private function summarize(string $name, array $definition): array
    {
        $children = $definition['children'] ?? null;
        $config = is_array($children) && is_array($children['config'] ?? null) ? $children['config'] : [];
        $template = is_string($definition['template'] ?? null) ? $definition['template'] : '';
        $fieldCount = 0;
        foreach (['content', 'design', 'advanced'] as $group) {
            $fieldCount += is_array($definition[$group] ?? null) ? count($definition[$group]) : 0;
        }
        return [
            'name' => $name,
            'label' => is_string($definition['label'] ?? null) ? $definition['label'] : $name,
            'category' => is_string($definition['category'] ?? null) ? $definition['category'] : 'Other',
            'description' => is_string($definition['description'] ?? null) ? $definition['description'] : '',
            'module' => strstr($template, '::', true) ?: null,
            'children' => match (true) {
                $children === true => 'any',
                $children === null => 'none',
                default => array_filter([
                    'accepts' => $config['accepts'] ?? null,
                    'excludes' => $config['excludes'] ?? null,
                    'max_children' => $config['max_children'] ?? null,
                    'max_nesting_level' => $config['max_nesting_level'] ?? null,
                ], static fn ($value): bool => $value !== null),
            },
            'require_parent' => !empty($definition['require_parent']),
            'hidden' => !empty($definition['hidden']),
            'field_count' => $fieldCount,
        ];
    }
}
