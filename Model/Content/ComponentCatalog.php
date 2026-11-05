<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Component\CollectorFactory;
use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\Mcp\Api\LoggerInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
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
     * @param CollectorFactory $collectorFactory
     * @param LoggerInterface $logger
     * @param MenuOptionScope $menuOptionScope
     */
    public function __construct(
        private readonly ComponentProvider $componentProvider,
        private readonly ComponentTree $componentTree,
        private readonly FieldValueFormat $fieldValueFormat,
        private readonly CollectorFactory $collectorFactory,
        private readonly LoggerInterface $logger,
        private readonly MenuOptionScope $menuOptionScope
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
                Cast::string($summary['name']) . ' ' . Cast::string($summary['label']) . ' '
                    . Cast::string($summary['description']),
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
        $liveDefinition = $this->hasOptionFields($definition) ? $this->collectLive($name) : [];
        $fields = [];
        foreach (['content', 'design', 'advanced'] as $group) {
            foreach (is_array($definition[$group] ?? null) ? $definition[$group] : [] as $field => $fieldDefinition) {
                if (!is_array($fieldDefinition) || !isset($fieldDefinition['type'])) {
                    continue;
                }
                $attributes = is_array($fieldDefinition['attributes'] ?? null) ? $fieldDefinition['attributes'] : [];
                $options = $fieldDefinition['options'] ?? null;
                $liveGroup = is_array($liveDefinition[$group] ?? null) ? $liveDefinition[$group] : [];
                $liveOptions = is_array($liveGroup[$field] ?? null) ? ($liveGroup[$field]['options'] ?? null) : null;
                if (is_array($options) && is_array($liveOptions)) {
                    $options = $liveOptions;
                }
                $hiddenOptions = 0;
                if (is_array($options) && $this->menuOptionScope->isMenuField($name, (string) $field)) {
                    ['options' => $options, 'hidden' => $hiddenOptions] = $this->menuOptionScope->filter($options);
                }
                $fields[] = array_filter([
                    'name' => (string) $field,
                    'group' => $group,
                    'type' => $fieldDefinition['type'],
                    'label' => $fieldDefinition['label'] ?? null,
                    'required' => !empty($attributes['required']),
                    'default_value' => $fieldDefinition['default_value'] ?? null,
                    'options' => is_array($options) ? $options : null,
                    'options_hidden_by_permissions' => $hiddenOptions > 0 ? $hiddenOptions : null,
                    'translate' => !empty($fieldDefinition['translate']),
                    'validation' => array_intersect_key(
                        $attributes,
                        array_flip(['min', 'max', 'minlength', 'maxlength', 'pattern', 'step'])
                    ),
                    'comment' => $attributes['comment'] ?? null,
                    'value_format' => $this->fieldValueFormat->get(Cast::string($fieldDefinition['type'])),
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
     * Whether any field of the definition has a list of options (resolved from a source model or declared inline).
     *
     * @param array $definition
     * @return bool
     * @phpstan-param array<mixed> $definition
     */
    private function hasOptionFields(array $definition): bool
    {
        foreach (['content', 'design', 'advanced'] as $group) {
            foreach (is_array($definition[$group] ?? null) ? $definition[$group] : [] as $fieldDefinition) {
                if (is_array($fieldDefinition) && is_array($fieldDefinition['options'] ?? null)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Component definition with its option source models resolved now.
     *
     * Hyvä resolves `options` source models (e.g. the Menu Builder's menu list) once, when it builds the `hyva_cms`
     * `components` cache entry, and does not clean that entry when a menu is saved. A fresh collector (the collector
     * memoises its result) reads the sources at request time; on any failure the cached definition stays the answer.
     *
     * @param string $name
     * @return array
     * @phpstan-return array<mixed>
     */
    private function collectLive(string $name): array
    {
        try {
            $components = $this->collectorFactory->create()->execute();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Hyvä CMS component options could not be read live, using the cached options: ' . $e->getMessage(),
                ['component' => $name, 'exception' => $e]
            );
            return [];
        }
        return is_array($components[$name] ?? null) ? $components[$name] : [];
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
