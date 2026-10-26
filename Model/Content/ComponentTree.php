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
 * Parses, walks, edits and validates Hyvä CMS content documents.
 *
 * A document is `{componentPath, contentType, contentId, uid, content, version, requestHash}`.
 * `content` holds the root nodes keyed by uid (an empty list when there are none);
 * every node carries `uid`, `component`, `componentPath`, `children` (list) and its field values.
 */
class ComponentTree
{
    private const ROOT_COMPONENT_PATH = 'elements/ROOT.phtml';
    private const FIELD_GROUPS = ['content', 'design', 'advanced'];
    private const RESERVED_KEYS = ['uid', 'component', 'componentPath', 'children'];

    /**
     * @param ComponentProvider $componentProvider
     * @param array $contentTypeConstraints Per content type: root_component, max_root_components,
     *     disallowed_components. Mirrors the Hyvä editor's entity-type settings, which Hyvä only enforces in JS.
     * @phpstan-param array<string, array<string, mixed>> $contentTypeConstraints
     */
    public function __construct(
        private readonly ComponentProvider $componentProvider,
        private readonly array $contentTypeConstraints = []
    ) {
    }

    /**
     * Parse.
     *
     * @param string $json
     * @param string $contentType
     * @param int $contentId
     * @return array
     * @throws LocalizedException
     * @phpstan-return array<string, mixed>
     */
    public function parse(string $json, string $contentType, int $contentId): array
    {
        if (trim($json) === '' || trim($json) === '[]') {
            return $this->newDocument($contentType, $contentId);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new LocalizedException(__('Stored %1 #%2 content is not valid JSON.', $contentType, $contentId));
        }
        return $this->normalizeDocument($data, $contentType, $contentId);
    }

    /**
     * Encode.
     *
     * @param array $document
     * @return string
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     */
    public function encode(array $document): string
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the content document as JSON.'));
        }
        return $json;
    }

    /**
     * New document.
     *
     * @param string $contentType
     * @param int $contentId
     * @return array
     * @phpstan-return array<string, mixed>
     */
    public function newDocument(string $contentType, int $contentId): array
    {
        return [
            'componentPath' => self::ROOT_COMPONENT_PATH,
            'contentType' => $contentType,
            'contentId' => (string) $contentId,
            'uid' => $this->newUid(),
            'content' => [],
            'version' => [],
            'requestHash' => '',
        ];
    }

    /**
     * Fill the root keys a stored or imported document may lack.
     *
     * @param array $data
     * @param string $contentType
     * @param int $contentId
     * @return array
     * @phpstan-param array<mixed> $data
     * @phpstan-return array<string, mixed>
     */
    public function normalizeDocument(array $data, string $contentType, int $contentId): array
    {
        $document = $this->newDocument($contentType, $contentId);
        foreach ($data as $key => $value) {
            $document[(string) $key] = $value;
        }
        $document['contentType'] = $contentType;
        $document['contentId'] = (string) $contentId;
        if (!is_string($document['uid']) || $document['uid'] === '') {
            $document['uid'] = $this->newUid();
        }
        if (!is_array($document['content'])) {
            $document['content'] = [];
        }
        // Hyvä presets (e.g. email templates) store root nodes as a list; the content-group schema wants a uid map.
        if ($document['content'] !== [] && array_is_list($document['content'])) {
            $document = $this->withRootNodes($document, array_values(array_filter($document['content'], 'is_array')));
        }
        if (!is_array($document['version'])) {
            $document['version'] = [];
        }
        return $document;
    }

    /**
     * Root nodes in document order.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array<string, mixed>>
     */
    public function getRootNodes(array $document): array
    {
        $content = $document['content'] ?? [];
        $nodes = [];
        if (is_array($content)) {
            foreach ($content as $node) {
                if (is_array($node)) {
                    $nodes[] = $this->stringKeys($node);
                }
            }
        }
        return $nodes;
    }

    /**
     * Depth-first walk. The visitor gets (node, parentUid or null, depth starting at 1).
     *
     * @param array $document
     * @param callable $visitor
     * @return void
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param callable(array<string, mixed>, string|null, int): void $visitor
     */
    public function walk(array $document, callable $visitor): void
    {
        foreach ($this->getRootNodes($document) as $node) {
            $this->walkNode($node, null, 1, $visitor);
        }
    }

    /**
     * Flat list of every node with its parent and depth (children stripped).
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array{node: array<string, mixed>, parent_uid: string|null, depth: int}>
     */
    public function flatten(array $document): array
    {
        $rows = [];
        $this->walk($document, function (array $node, ?string $parentUid, int $depth) use (&$rows): void {
            $rows[] = ['node' => $node, 'parent_uid' => $parentUid, 'depth' => $depth];
        });
        return $rows;
    }

    /**
     * Find node.
     *
     * @param array $document
     * @param string $uid
     * @return array|null
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, mixed>|null
     */
    public function findNode(array $document, string $uid): ?array
    {
        $found = null;
        $this->walk($document, function (array $node) use ($uid, &$found): void {
            if ($found === null && ($node['uid'] ?? null) === $uid) {
                $found = $node;
            }
        });
        return $found;
    }

    /**
     * Find parent uid.
     *
     * @param array $document
     * @param string $uid
     * @return string|null Parent uid, null for root nodes or when not found.
     * @phpstan-param array<string, mixed> $document
     */
    public function findParentUid(array $document, string $uid): ?string
    {
        $parent = null;
        $this->walk($document, function (array $node, ?string $parentUid) use ($uid, &$parent): void {
            if (($node['uid'] ?? null) === $uid) {
                $parent = $parentUid;
            }
        });
        return $parent;
    }

    /**
     * Build a new node with registry defaults, then apply the given field values.
     *
     * @param string $component
     * @param array $fields
     * @param array $children
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $fields
     * @phpstan-param list<array<string, mixed>> $children
     * @phpstan-return array<string, mixed>
     */
    public function createNode(string $component, array $fields = [], array $children = []): array
    {
        $definition = $this->componentProvider->getComponent($component);
        if ($definition === null) {
            throw new LocalizedException(__('Unknown Hyvä CMS component "%1".', $component));
        }

        $node = [
            'uid' => $this->newUid(),
            'component' => $component,
            'componentPath' => is_string($definition['template'] ?? null) ? $definition['template'] : '',
            'children' => $children,
        ];
        foreach (self::FIELD_GROUPS as $group) {
            $groupFields = $definition[$group] ?? [];
            if (!is_array($groupFields)) {
                continue;
            }
            foreach ($groupFields as $fieldName => $fieldDefinition) {
                if (is_array($fieldDefinition) && array_key_exists('default_value', $fieldDefinition)) {
                    $node[(string) $fieldName] = $fieldDefinition['default_value'];
                }
            }
        }
        foreach ($fields as $name => $value) {
            if (!in_array((string) $name, self::RESERVED_KEYS, true)) {
                $node[(string) $name] = $value;
            }
        }
        return $node;
    }

    /**
     * Normalise a caller-supplied node: assign missing uids (recursively) and the component template.
     *
     * @param array $node
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $node
     * @phpstan-return array<string, mixed>
     */
    public function prepareNode(array $node): array
    {
        $node = $this->stringKeys($node);
        $component = $node['component'] ?? null;
        if (!is_string($component) || $component === '') {
            throw new LocalizedException(__('Every component node needs a "component" name.'));
        }
        if (!is_string($node['uid'] ?? null) || $node['uid'] === '') {
            $node['uid'] = $this->newUid();
        }
        if (!is_string($node['componentPath'] ?? null) || $node['componentPath'] === '') {
            $definition = $this->componentProvider->getComponent($component);
            $node['componentPath'] = is_string($definition['template'] ?? null) ? $definition['template'] : '';
        }
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $children[] = $this->prepareNode($child);
            }
        }
        $node['children'] = $children;
        return $node;
    }

    /**
     * Insert node.
     *
     * @param array $document
     * @param array $node
     * @param string|null $parentUid Null inserts at the root.
     * @param int|null $position Zero-based; null appends.
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<string, mixed> $node
     * @phpstan-return array<string, mixed>
     */
    public function insertNode(array $document, array $node, ?string $parentUid = null, ?int $position = null): array
    {
        $existing = [];
        $this->walk($document, function (array $candidate) use (&$existing): void {
            if (is_string($candidate['uid'] ?? null)) {
                $existing[$candidate['uid']] = true;
            }
        });
        $this->walkNode($node, null, 1, function (array $candidate) use ($existing): void {
            if (is_string($candidate['uid'] ?? null) && isset($existing[$candidate['uid']])) {
                throw new LocalizedException(__('Uid "%1" is already used in this document.', $candidate['uid']));
            }
        });
        if ($parentUid === null) {
            $roots = $this->getRootNodes($document);
            $roots = $this->spliceIn($roots, $node, $position);
            return $this->withRootNodes($document, $roots);
        }

        $inserted = false;
        $document = $this->mapNodes($document, function (array $candidate) use (
            $parentUid,
            $node,
            $position,
            &$inserted
        ) {
            if (($candidate['uid'] ?? null) === $parentUid) {
                $children = $this->childList($candidate);
                $candidate['children'] = $this->spliceIn($children, $node, $position);
                $inserted = true;
            }
            return $candidate;
        });
        if (!$inserted) {
            throw new LocalizedException(__('Parent component "%1" was not found.', $parentUid));
        }
        return $document;
    }

    /**
     * Remove node.
     *
     * @param array $document
     * @param string $uid
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array{document: array<string, mixed>, removed: array<string, mixed>}
     */
    public function removeNode(array $document, string $uid): array
    {
        $removed = $this->findNode($document, $uid);
        if ($removed === null) {
            throw new LocalizedException(__('Component "%1" was not found.', $uid));
        }

        $roots = array_values(array_filter(
            $this->getRootNodes($document),
            static fn (array $node): bool => ($node['uid'] ?? null) !== $uid
        ));
        $document = $this->withRootNodes($document, $roots);
        $document = $this->mapNodes($document, function (array $candidate) use ($uid) {
            $candidate['children'] = array_values(array_filter(
                $this->childList($candidate),
                static fn (array $child): bool => ($child['uid'] ?? null) !== $uid
            ));
            return $candidate;
        });

        return ['document' => $document, 'removed' => $removed];
    }

    /**
     * Move node.
     *
     * @param array $document
     * @param string $uid
     * @param string|null $newParentUid Null moves to the root.
     * @param int|null $position
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, mixed>
     */
    public function moveNode(array $document, string $uid, ?string $newParentUid, ?int $position = null): array
    {
        if ($newParentUid !== null) {
            $node = $this->findNode($document, $uid);
            if ($node !== null && $this->containsUid($node, $newParentUid)) {
                throw new LocalizedException(__('Cannot move component "%1" into its own subtree.', $uid));
            }
        }
        $result = $this->removeNode($document, $uid);
        return $this->insertNode($result['document'], $result['removed'], $newParentUid, $position);
    }

    /**
     * Replace node.
     *
     * @param array $document
     * @param string $uid
     * @param array $replacement
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<string, mixed> $replacement
     * @phpstan-return array<string, mixed>
     */
    public function replaceNode(array $document, string $uid, array $replacement): array
    {
        $parentUid = $this->findParentUid($document, $uid);
        $position = $this->indexOf($document, $uid);
        $result = $this->removeNode($document, $uid);
        return $this->insertNode($result['document'], $replacement, $parentUid, $position);
    }

    /**
     * Set field.
     *
     * @param array $document
     * @param string $uid
     * @param string $field
     * @param mixed $value
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, mixed>
     */
    public function setField(array $document, string $uid, string $field, mixed $value): array
    {
        if (in_array($field, self::RESERVED_KEYS, true)) {
            throw new LocalizedException(__('Field "%1" cannot be set directly.', $field));
        }
        $found = false;
        $document = $this->mapNodes($document, function (array $node) use ($uid, $field, $value, &$found) {
            if (($node['uid'] ?? null) === $uid) {
                $node[$field] = $value;
                $found = true;
            }
            return $node;
        });
        if (!$found) {
            throw new LocalizedException(__('Component "%1" was not found.', $uid));
        }
        return $document;
    }

    /**
     * Apply a callback to every node bottom-up; the callback returns the updated node.
     *
     * @param array $document
     * @param callable $mapper
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param callable(array<string, mixed>): array<string, mixed> $mapper
     * @phpstan-return array<string, mixed>
     */
    public function mapNodes(array $document, callable $mapper): array
    {
        $roots = [];
        foreach ($this->getRootNodes($document) as $node) {
            $roots[] = $this->mapNode($node, $mapper);
        }
        return $this->withRootNodes($document, $roots);
    }

    /**
     * Component name → number of nodes using it.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, int>
     */
    public function countComponents(array $document): array
    {
        $counts = [];
        $this->walk($document, function (array $node) use (&$counts): void {
            $name = is_string($node['component'] ?? null) ? $node['component'] : '?';
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        });
        ksort($counts);
        return $counts;
    }

    /**
     * Structural validation against the component registry.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return list<array{severity: string, code: string, uid: string|null, component: string|null,
     *     message: string}>
     */
    public function validate(array $document): array
    {
        $issues = [];
        foreach (['componentPath', 'contentType', 'contentId', 'uid', 'content'] as $rootKey) {
            if (!array_key_exists($rootKey, $document)) {
                $issues[] = $this->issue(
                    'error',
                    'missing_root_key',
                    null,
                    null,
                    sprintf('Document lacks "%s".', $rootKey)
                );
            }
        }

        $seen = [];
        $components = $this->componentProvider->getComponents();
        $this->walk($document, function (
            array $node,
            ?string $parentUid,
            int $depth
        ) use (
            &$issues,
            &$seen,
            $components,
            $document
): void {
            $uid = is_string($node['uid'] ?? null) && $node['uid'] !== '' ? $node['uid'] : null;
            $name = is_string($node['component'] ?? null) ? $node['component'] : null;

            if ($uid === null) {
                $issues[] = $this->issue('error', 'missing_uid', null, $name, 'Component has no uid.');
            } elseif (isset($seen[$uid])) {
                $issues[] = $this->issue('error', 'duplicate_uid', $uid, $name, 'Uid is used more than once.');
            } else {
                $seen[$uid] = true;
            }

            if ($name === null) {
                $issues[] = $this->issue('error', 'missing_component', $uid, null, 'Node has no component name.');
                return;
            }
            $definition = $components[$name] ?? null;
            if (!is_array($definition)) {
                $code = $this->componentProvider->isComponentDisabled($name)
                    ? 'disabled_component'
                    : 'unknown_component';
                $issues[] = $this->issue(
                    'error',
                    $code,
                    $uid,
                    $name,
                    sprintf(
                        'Component "%s" is %s.',
                        $name,
                        $code === 'disabled_component' ? 'disabled' : 'not registered'
                    )
                );
                return;
            }

            if ($parentUid === null && !empty($definition['require_parent'])) {
                $issues[] = $this->issue(
                    'error',
                    'requires_parent',
                    $uid,
                    $name,
                    sprintf('"%s" can only be placed inside another component.', $name)
                );
            }
            if ($parentUid !== null) {
                $parent = $this->findNode($document, $parentUid);
                $parentName = is_string($parent['component'] ?? null) ? $parent['component'] : '';
                $parentDefinition = $components[$parentName] ?? null;
                if (is_array($parentDefinition) && !$this->acceptsChild($parentDefinition, $name)) {
                    $issues[] = $this->issue(
                        'error',
                        'invalid_child',
                        $uid,
                        $name,
                        sprintf('"%s" does not accept "%s" as a child.', $parentName, $name)
                    );
                }
            }

            $children = $this->childList($node);
            $childConfig = $definition['children'] ?? null;
            if ($children !== [] && $childConfig === null) {
                $issues[] = $this->issue(
                    'error',
                    'children_not_allowed',
                    $uid,
                    $name,
                    sprintf('"%s" does not accept children.', $name)
                );
            }
            $maxChildren = is_array($childConfig) ? ($childConfig['config']['max_children'] ?? null) : null;
            if (is_numeric($maxChildren) && count($children) > (int) $maxChildren) {
                $issues[] = $this->issue(
                    'error',
                    'too_many_children',
                    $uid,
                    $name,
                    sprintf('"%s" accepts at most %d children.', $name, (int) $maxChildren)
                );
            }

            foreach ($this->fieldDefinitions($definition) as $field => $fieldDefinition) {
                $value = $node[$field] ?? null;
                $attributes = is_array($fieldDefinition['attributes'] ?? null) ? $fieldDefinition['attributes'] : [];
                $required = !empty($attributes['required']);
                if ($required && ($value === null || $value === '' || $value === [])) {
                    $issues[] = $this->issue(
                        'error',
                        'missing_required_field',
                        $uid,
                        $name,
                        sprintf('Required field "%s" is empty.', $field)
                    );
                }
                if (($fieldDefinition['type'] ?? null) === 'option_list' && is_array($value)
                    && ($attributes['default_selection'] ?? null) === 'single'
                    && count(array_filter($value, static fn ($row): bool => is_array($row) && !empty($row['default'])))
                        > 1
                ) {
                    $issues[] = $this->issue(
                        'error',
                        'too_many_defaults',
                        $uid,
                        $name,
                        sprintf('Field "%s" allows only one option marked "default".', $field)
                    );
                }
                if (($fieldDefinition['type'] ?? null) === 'link' && $value !== null && $value !== ''
                    && $value !== [] && !$this->isUsableLink($value)
                ) {
                    $issues[] = $this->issue(
                        'warning',
                        'invalid_link',
                        $uid,
                        $name,
                        sprintf(
                            'Link field "%s" needs {"type": ..., "value": ...}; keys like "url" are ignored and the '
                                . 'link renders an empty href.',
                            $field
                        )
                    );
                }
                if ($value !== null && $value !== '' && ($fieldDefinition['type'] ?? null) === 'select') {
                    $allowed = $this->optionValues($fieldDefinition['options'] ?? null);
                    if ($allowed !== null && is_scalar($value) && !in_array((string) $value, $allowed, true)) {
                        $issues[] = $this->issue(
                            'warning',
                            'invalid_option',
                            $uid,
                            $name,
                            sprintf(
                                'Field "%s" value "%s" is not one of: %s.',
                                $field,
                                (string) $value,
                                implode(', ', $allowed)
                            )
                        );
                    }
                }
            }
        });

        foreach ($this->rootConstraintIssues($document, $components) as $issue) {
            $issues[] = $issue;
        }
        return $issues;
    }

    /**
     * Whether a link field value has the `type` and `value` Hyvä reads.
     *
     * @param mixed $value
     * @return bool
     */
    private function isUsableLink(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['type'] ?? null) && $value['type'] !== ''
            && isset($value['value']) && $value['value'] !== '';
    }

    /**
     * Root rules the Hyvä editor applies in JS only.
     *
     * Covers protected_root components and, per content type, root_component / max_root_components /
     * disallowed_components.
     *
     * @param array $document
     * @param array $components
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<mixed> $components
     * @phpstan-return list<array{severity: string, code: string, uid: string|null, component: string|null,
     *     message: string}>
     */
    private function rootConstraintIssues(array $document, array $components): array
    {
        $issues = [];
        $roots = $this->getRootNodes($document);
        $rootNames = array_map(static fn (array $node): string => Cast::string($node['component'] ?? ''), $roots);
        $constraints = $this->contentTypeConstraints[Cast::string($document['contentType'] ?? '')] ?? [];

        $rootComponent = Cast::string($constraints['root_component'] ?? '');
        if ($rootComponent !== '' && $rootNames !== [$rootComponent]) {
            $issues[] = $this->issue('error', 'root_component_required', null, $rootComponent, sprintf(
                'This content needs exactly one top-level "%s" holding everything else.',
                $rootComponent
            ));
        }
        $maxRoots = $constraints['max_root_components'] ?? null;
        if (is_numeric($maxRoots) && count($roots) > (int) $maxRoots) {
            $issues[] = $this->issue('error', 'too_many_root_components', null, null, sprintf(
                'This content allows at most %d top-level component(s), %d given.',
                (int) $maxRoots,
                count($roots)
            ));
        }
        $disallowed = is_array($constraints['disallowed_components'] ?? null)
            ? array_map('strval', $constraints['disallowed_components'])
            : [];
        $visitor = function (array $node, ?string $parentUid) use (&$issues, $disallowed, $components, $roots): void {
            $name = Cast::string($node['component'] ?? '');
            $uid = is_string($node['uid'] ?? null) ? $node['uid'] : null;
            if (in_array($name, $disallowed, true)) {
                $issues[] = $this->issue('error', 'disallowed_component', $uid, $name, sprintf(
                    '"%s" cannot be used in this content type.',
                    $name
                ));
            }
            $definition = $components[$name] ?? null;
            if (is_array($definition) && !empty($definition['root_only']) && $parentUid !== null) {
                $issues[] = $this->issue('error', 'root_only', $uid, $name, sprintf(
                    '"%s" can only be a top-level component.',
                    $name
                ));
            }
            if (is_array($definition) && !empty($definition['protected_root'])
                && ($parentUid !== null || count($roots) > 1)
            ) {
                $issues[] = $this->issue('error', 'protected_root', $uid, $name, sprintf(
                    '"%s" must be the only top-level component.',
                    $name
                ));
            }
        };
        $this->walk($document, $visitor);
        return $issues;
    }

    /**
     * Accepts child.
     *
     * @param array $definition
     * @param string $childName
     * @return bool
     * @phpstan-param array<string, mixed> $definition
     */
    public function acceptsChild(array $definition, string $childName): bool
    {
        $children = $definition['children'] ?? null;
        if ($children === true) {
            return true;
        }
        if (!is_array($children)) {
            return false;
        }
        $config = is_array($children['config'] ?? null) ? $children['config'] : [];
        $accepts = is_array($config['accepts'] ?? null) ? $config['accepts'] : null;
        $excludes = is_array($config['excludes'] ?? null) ? $config['excludes'] : [];
        if ($accepts !== null && !in_array($childName, $accepts, true)) {
            return false;
        }
        return !in_array($childName, $excludes, true);
    }

    /**
     * New uid.
     *
     * @return string
     */
    public function newUid(): string
    {
        return uniqid() . bin2hex(random_bytes(2));
    }

    /**
     * Content fields of a component as the editor's translation panel sees them (name, type, translate flag).
     *
     * @param string $component
     * @return array
     * @phpstan-return array<string, array{type: string, translate: bool}>
     */
    public function contentFields(string $component): array
    {
        $definition = $component === '' ? null : $this->componentProvider->getComponent($component);
        $content = is_array($definition) ? ($definition['content'] ?? null) : null;
        $fields = [];
        foreach (is_array($content) ? $content : [] as $name => $field) {
            if (is_array($field) && isset($field['type'])) {
                $fields[(string) $name] = [
                    'type' => Cast::string($field['type']),
                    'translate' => !empty($field['translate']),
                ];
            }
        }
        return $fields;
    }

    /**
     * Field definitions.
     *
     * @param array $definition
     * @return array
     * @phpstan-param array<string, mixed> $definition
     * @phpstan-return array<string, array<string, mixed>>
     */
    public function fieldDefinitions(array $definition): array
    {
        $fields = [];
        foreach (self::FIELD_GROUPS as $group) {
            $groupFields = $definition[$group] ?? [];
            if (!is_array($groupFields)) {
                continue;
            }
            foreach ($groupFields as $name => $fieldDefinition) {
                if (is_array($fieldDefinition) && isset($fieldDefinition['type'])) {
                    $fields[(string) $name] = $this->stringKeys($fieldDefinition);
                }
            }
        }
        return $fields;
    }

    /**
     * Walk node.
     *
     * @param array $node
     * @param string|null $parentUid
     * @param int $depth
     * @param callable $visitor
     * @return void
     * @phpstan-param array<string, mixed> $node
     * @phpstan-param callable(array<string, mixed>, string|null, int): void $visitor
     */
    private function walkNode(array $node, ?string $parentUid, int $depth, callable $visitor): void
    {
        $visitor($node, $parentUid, $depth);
        $uid = is_string($node['uid'] ?? null) ? $node['uid'] : null;
        foreach ($this->childList($node) as $child) {
            $this->walkNode($child, $uid, $depth + 1, $visitor);
        }
    }

    /**
     * Map node.
     *
     * @param array $node
     * @param callable $mapper
     * @return array
     * @phpstan-param array<string, mixed> $node
     * @phpstan-param callable(array<string, mixed>): array<string, mixed> $mapper
     * @phpstan-return array<string, mixed>
     */
    private function mapNode(array $node, callable $mapper): array
    {
        $children = [];
        foreach ($this->childList($node) as $child) {
            $children[] = $this->mapNode($child, $mapper);
        }
        $node['children'] = $children;
        return $mapper($node);
    }

    /**
     * Child list.
     *
     * @param array $node
     * @return array
     * @phpstan-param array<string, mixed> $node
     * @phpstan-return list<array<string, mixed>>
     */
    private function childList(array $node): array
    {
        $children = [];
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child)) {
                $children[] = $this->stringKeys($child);
            }
        }
        return $children;
    }

    /**
     * Write root nodes back as the uid-keyed map the Hyvä content-group schema requires (pages, blocks and menus).
     *
     * @param array $document
     * @param array $roots
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param list<array<string, mixed>> $roots
     * @phpstan-return array<string, mixed>
     */
    private function withRootNodes(array $document, array $roots): array
    {
        $map = [];
        foreach ($roots as $node) {
            $uid = is_string($node['uid'] ?? null) && $node['uid'] !== '' ? $node['uid'] : $this->newUid();
            $node['uid'] = $uid;
            $map[$uid] = $node;
        }
        $document['content'] = $map;
        return $document;
    }

    /**
     * Splice in.
     *
     * @param array $list
     * @param array $node
     * @param int|null $position
     * @return array
     * @phpstan-param list<array<string, mixed>> $list
     * @phpstan-param array<string, mixed> $node
     * @phpstan-return list<array<string, mixed>>
     */
    private function spliceIn(array $list, array $node, ?int $position): array
    {
        if ($position === null || $position >= count($list)) {
            $list[] = $node;
            return $list;
        }
        array_splice($list, max(0, $position), 0, [$node]);
        return array_values($list);
    }

    /**
     * Index of.
     *
     * @param array $document
     * @param string $uid
     * @return int|null
     * @phpstan-param array<string, mixed> $document
     */
    private function indexOf(array $document, string $uid): ?int
    {
        $parentUid = $this->findParentUid($document, $uid);
        $siblings = $parentUid === null
            ? $this->getRootNodes($document)
            : $this->childList($this->findNode($document, $parentUid) ?? []);
        foreach ($siblings as $index => $node) {
            if (($node['uid'] ?? null) === $uid) {
                return $index;
            }
        }
        return null;
    }

    /**
     * Contains uid.
     *
     * @param array $node
     * @param string $uid
     * @return bool
     * @phpstan-param array<string, mixed> $node
     */
    private function containsUid(array $node, string $uid): bool
    {
        if (($node['uid'] ?? null) === $uid) {
            return true;
        }
        foreach ($this->childList($node) as $child) {
            if ($this->containsUid($child, $uid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Option values.
     *
     * @param mixed $options
     * @return array|null Null when options come from a source model.
     * @phpstan-return list<string>|null
     */
    private function optionValues(mixed $options): ?array
    {
        if (!is_array($options)) {
            return null;
        }
        $values = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $values[] = (string) $option['value'];
            }
        }
        return $values;
    }

    /**
     * Issue.
     *
     * @param string $severity
     * @param string $code
     * @param string|null $uid
     * @param string|null $component
     * @param string $message
     * @return array
     * @phpstan-return array{severity: string, code: string, uid: string|null, component: string|null, message: string}
     */
    private function issue(string $severity, string $code, ?string $uid, ?string $component, string $message): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'uid' => $uid,
            'component' => $component,
            'message' => $message,
        ];
    }

    /**
     * String keys.
     *
     * @param array $data
     * @return array
     * @phpstan-param array<mixed> $data
     * @phpstan-return array<string, mixed>
     */
    private function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }

    /**
     * Give declared image field values an imageOptions.path.
     *
     * Hyvä's ImageIdBackfiller only treats values with imageOptions or preview_url as images; with the path it
     * can store the Media Gallery asset id on save.
     *
     * @param array $document
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, mixed>
     */
    public function markImageFields(array $document): array
    {
        return $this->mapNodes($document, function (array $node): array {
            $definition = $this->componentProvider->getComponent(Cast::string($node['component'] ?? ''));
            if (!is_array($definition)) {
                return $node;
            }
            foreach ($this->fieldDefinitions($this->stringKeys($definition)) as $name => $field) {
                $value = $node[$name] ?? null;
                if (($field['type'] ?? null) === 'image' && is_array($value) && is_string($value['src'] ?? null)
                    && $value['src'] !== '' && !isset($value['imageOptions']) && !isset($value['preview_url'])
                ) {
                    $value['imageOptions'] = ['path' => $value['src']];
                    $node[$name] = $value;
                }
            }
            return $node;
        });
    }
}
