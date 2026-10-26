<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magento\Framework\Exception\LocalizedException;

/**
 * Applies a list of draft operations to a content document.
 *
 * Operations: add {component, fields?, children?, parent_uid?, position?} (or {node, parent_uid?, position?}),
 * remove {uid}, move {uid, parent_uid?, position?}, set_field {uid, field, value},
 * set_fields {uid, fields}, replace_node {uid, node}, replace_text {find, replace, fields?}.
 */
class DraftEditor
{
    public const OPERATIONS = ['add', 'remove', 'move', 'set_field', 'set_fields', 'replace_node', 'replace_text'];

    /**
     * @param ComponentTree $componentTree
     */
    public function __construct(
        private readonly ComponentTree $componentTree
    ) {
    }

    /**
     * Apply.
     *
     * @param array $document
     * @param array $operations
     * @return array
     * @throws LocalizedException On the first invalid operation; nothing is applied then.
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<mixed> $operations
     * @phpstan-return array{document: array<string, mixed>, applied: list<array<string, mixed>>}
     */
    public function apply(array $document, array $operations): array
    {
        $applied = [];
        foreach (array_values($operations) as $index => $operation) {
            if (!is_array($operation)) {
                throw new LocalizedException(__('Operation #%1 must be an object.', $index));
            }
            $op = is_string($operation['op'] ?? null) ? $operation['op'] : '';
            try {
                [$document, $summary] = $this->applyOne($document, $op, $operation);
            } catch (LocalizedException $e) {
                throw new LocalizedException(__('Operation #%1 (%2) failed: %3', $index, $op, $e->getMessage()));
            }
            $applied[] = ['index' => $index, 'op' => $op] + $summary;
        }
        return ['document' => $document, 'applied' => $applied];
    }

    /**
     * Apply one.
     *
     * @param array $document
     * @param string $op
     * @param array $operation
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<mixed> $operation
     * @phpstan-return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyOne(array $document, string $op, array $operation): array
    {
        $uid = is_string($operation['uid'] ?? null) ? $operation['uid'] : null;
        $parentUid = is_string($operation['parent_uid'] ?? null)
            && $operation['parent_uid'] !== '' ? $operation['parent_uid'] : null;
        $position = is_int($operation['position'] ?? null) ? $operation['position'] : null;

        switch ($op) {
            case 'add':
                $spec = is_array($operation['node'] ?? null) ? $operation['node'] : $operation;
                $node = $this->buildNode($spec);
                $document = $this->componentTree->insertNode($document, $node, $parentUid, $position);
                return [$document, ['uid' => $node['uid'], 'component' => $node['component']]];
            case 'remove':
                $result = $this->componentTree->removeNode($document, $this->requireUid($uid));
                return [
                    $result['document'],
                    ['uid' => $uid, 'removed_component' => $result['removed']['component'] ?? null],
                ];
            case 'move':
                return [
                    $this->componentTree->moveNode($document, $this->requireUid($uid), $parentUid, $position),
                    ['uid' => $uid],
                ];
            case 'set_field':
                $field = is_string($operation['field'] ?? null) ? $operation['field'] : '';
                if ($field === '') {
                    throw new LocalizedException(__('"field" is required.'));
                }
                return [
                    $this->componentTree->setField(
                        $document,
                        $this->requireUid($uid),
                        $field,
                        $operation['value'] ?? null
                    ),
                    ['uid' => $uid, 'field' => $field],
                ];
            case 'set_fields':
                $fields = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];
                foreach ($fields as $field => $value) {
                    $document = $this->componentTree->setField(
                        $document,
                        $this->requireUid($uid),
                        (string) $field,
                        $value
                    );
                }
                return [$document, ['uid' => $uid, 'fields' => array_map('strval', array_keys($fields))]];
            case 'replace_node':
                if (!is_array($operation['node'] ?? null)) {
                    throw new LocalizedException(__('"node" is required.'));
                }
                $node = $this->buildNode($operation['node']);
                return [
                    $this->componentTree->replaceNode($document, $this->requireUid($uid), $node),
                    ['uid' => $uid, 'new_uid' => $node['uid']],
                ];
            case 'replace_text':
                return $this->replaceText($document, $operation);
            default:
                throw new LocalizedException(
                    __('Unknown op "%1". Use one of: %2.', $op, implode(', ', self::OPERATIONS))
                );
        }
    }

    /**
     * Build a node from `{component, fields?, children?, uid?}` or from a raw node (field values on the node itself).
     *
     * Registry defaults fill every field that is not given.
     *
     * @param array $spec
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $spec
     * @phpstan-return array<string, mixed>
     */
    public function buildNode(array $spec): array
    {
        $component = is_string($spec['component'] ?? null) ? $spec['component'] : '';
        if ($component === '') {
            throw new LocalizedException(__('Every component needs a "component" name.'));
        }
        if (is_array($spec['fields'] ?? null)) {
            $fields = $spec['fields'];
        } else {
            $fields = $spec;
            unset(
                $fields['uid'],
                $fields['component'],
                $fields['componentPath'],
                $fields['children'],
                $fields['op'],
                $fields['parent_uid'],
                $fields['position']
            );
        }
        $children = [];
        foreach (is_array($spec['children'] ?? null) ? $spec['children'] : [] as $child) {
            if (is_array($child)) {
                $children[] = $this->buildNode($child);
            }
        }
        $node = $this->componentTree->createNode($component, $fields, $children);
        if (is_string($spec['uid'] ?? null) && $spec['uid'] !== '') {
            $node['uid'] = $spec['uid'];
        }
        return $node;
    }

    /**
     * Build a root list of nodes.
     *
     * @param array $specs
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<mixed> $specs
     * @phpstan-return list<array<string, mixed>>
     */
    public function buildNodes(array $specs): array
    {
        $nodes = [];
        foreach ($specs as $spec) {
            if (is_array($spec)) {
                $nodes[] = $this->buildNode($spec);
            }
        }
        return $nodes;
    }

    /**
     * Bulk text replacement in string fields (optionally limited to the named fields).
     *
     * @param array $document
     * @param array $operation
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-param array<mixed> $operation
     * @phpstan-return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function replaceText(array $document, array $operation): array
    {
        $find = is_string($operation['find'] ?? null) ? $operation['find'] : '';
        $replace = is_string($operation['replace'] ?? null) ? $operation['replace'] : '';
        if ($find === '') {
            throw new LocalizedException(__('"find" is required.'));
        }
        $onlyFields = is_array($operation['fields'] ?? null) ? array_map('strval', $operation['fields']) : [];
        $count = 0;
        $document = $this->componentTree->mapNodes($document, function (array $node) use (
            $find,
            $replace,
            $onlyFields,
            &$count
        ): array {
            foreach ($node as $field => $value) {
                if (in_array($field, ['uid', 'component', 'componentPath', 'children'], true)) {
                    continue;
                }
                if ($onlyFields !== [] && !in_array($field, $onlyFields, true)) {
                    continue;
                }
                if (is_string($value) && str_contains($value, $find)) {
                    $node[$field] = str_replace($find, $replace, $value, $replaced);
                    $count += $replaced;
                }
            }
            return $node;
        });
        return [$document, ['replacements' => $count]];
    }

    /**
     * Require uid.
     *
     * @param string|null $uid
     * @return string
     * @throws LocalizedException
     */
    private function requireUid(?string $uid): string
    {
        if ($uid === null || $uid === '') {
            throw new LocalizedException(__('"uid" is required.'));
        }
        return $uid;
    }
}
