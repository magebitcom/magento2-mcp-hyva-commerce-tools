<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Iterates every stored Hyvä CMS document (pages, blocks and, when the table exists, menus; draft and published).
 * Menus and Form Builder forms are read with plain SQL so this class works without Hyva_MenuBuilder and
 * Hyva_FormBuilder.
 */
class ContentScanner
{
    public const EDITION_DRAFT = 'draft';
    public const EDITION_PUBLISHED = 'published';

    /**
     * Entity type of Form Builder forms in usage entries (Hyvä's content type of a form tree).
     */
    public const TYPE_FORM = 'form';

    /**
     * Forms read, parsed and walked per batch, so a store with many or large forms is never held in memory at once.
     */
    public const FORM_BATCH_SIZE = 100;

    /**
     * Magento CMS rows per type, loaded once per request for repeated content searches.
     *
     * @var array
     * @phpstan-var array<string, list<array<string, mixed>>>
     */
    private array $magentoRows = [];

    /**
     * @param ContentIndex $contentIndex
     * @param ComponentTree $componentTree
     * @param ResourceConnection $resourceConnection
     * @param FeatureCheckerInterface $featureChecker
     * @param int $formBatchSize Forms per batch in findFormNodes()
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ResourceConnection $resourceConnection,
        private readonly FeatureCheckerInterface $featureChecker,
        private readonly int $formBatchSize = self::FORM_BATCH_SIZE
    ) {
    }

    /**
     * Get documents.
     *
     * @param array $types
     * @param bool $includeDraft
     * @param bool $includePublished
     * @param bool $ignoreMenuAcl Read menus whenever Menu Builder is installed and enabled, see getMenuRows().
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     document: array<string, mixed>|null, error: string|null}>
     */
    public function getDocuments(
        array $types = ContentLocator::TYPES,
        bool $includeDraft = true,
        bool $includePublished = true,
        bool $ignoreMenuAcl = false
    ): array {
        $documents = [];
        foreach ($types as $type) {
            $rows = $type === ContentLocator::TYPE_MENU
                ? $this->getMenuRows($ignoreMenuAcl)
                : $this->contentIndex->getRows($type, true);
            array_push($documents, ...$this->documentsOfRows($type, $rows, $includeDraft, $includePublished));
        }
        return $documents;
    }

    /**
     * Parsed draft / published documents of rows already read with their content.
     *
     * Rows come from ContentIndex::getRows() with content, or are menu rows; empty editions are skipped, unparsable
     * ones come back with an error.
     *
     * @param string $type
     * @param array $rows
     * @param bool $includeDraft
     * @param bool $includePublished
     * @return array
     * @phpstan-param list<array<string, mixed>> $rows
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     document: array<string, mixed>|null, error: string|null}>
     */
    public function documentsOfRows(
        string $type,
        array $rows,
        bool $includeDraft = true,
        bool $includePublished = true
    ): array {
        $documents = [];
        foreach ($rows as $row) {
            $editions = [];
            if ($includeDraft) {
                $editions[self::EDITION_DRAFT] = $row['draft_content'] ?? null;
            }
            if ($includePublished) {
                $editions[self::EDITION_PUBLISHED] = $row['published_content'] ?? null;
            }
            foreach ($editions as $edition => $json) {
                if (!is_string($json) || trim($json) === '' || trim($json) === '[]') {
                    continue;
                }
                $document = null;
                $error = null;
                try {
                    $document = $this->componentTree->parse($json, $type, Cast::int($row['id']));
                } catch (LocalizedException $e) {
                    $error = $e->getMessage();
                }
                $documents[] = [
                    'entity_type' => $type,
                    'id' => Cast::int($row['id']),
                    'identifier' => Cast::string($row['identifier']),
                    'title' => Cast::string($row['title']),
                    'edition' => $edition,
                    'document' => $document,
                    'error' => $error,
                ];
            }
        }
        return $documents;
    }

    /**
     * Nodes matching a predicate, with their location.
     *
     * Only a node that names a menu (a menu widget) gets menu_identifier, so other usage rows do not carry it.
     *
     * @param callable $predicate
     * @param array $types
     * @param bool $ignoreMenuAcl Read menus whenever Menu Builder is installed and enabled, see getMenuRows().
     * @return array
     * @phpstan-param callable(array<string, mixed>): bool $predicate
     * @phpstan-param list<string> $types
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     uid: string|null, component: string|null, menu_identifier?: string}>
     */
    public function findNodes(
        callable $predicate,
        array $types = ContentLocator::TYPES,
        bool $ignoreMenuAcl = false
    ): array {
        $matches = [];
        foreach ($this->getDocuments($types, true, true, $ignoreMenuAcl) as $entry) {
            if ($entry['document'] === null) {
                continue;
            }
            $this->componentTree->walk($entry['document'], function (array $node) use (
                $predicate,
                $entry,
                &$matches
            ): void {
                if ($predicate($node)) {
                    $match = [
                        'entity_type' => $entry['entity_type'],
                        'id' => $entry['id'],
                        'identifier' => $entry['identifier'],
                        'title' => $entry['title'],
                        'edition' => $entry['edition'],
                        'uid' => is_string($node['uid'] ?? null) ? $node['uid'] : null,
                        'component' => is_string($node['component'] ?? null) ? $node['component'] : null,
                    ];
                    if (is_string($node['menu_identifier'] ?? null)) {
                        $match['menu_identifier'] = $node['menu_identifier'];
                    }
                    $matches[] = $match;
                }
            });
        }
        return $matches;
    }

    /**
     * Nodes of Form Builder form trees (draft and published) matching a predicate, with the form and its store views.
     *
     * Forms are read whenever Form Builder is installed and enabled, whatever the caller's form permission: this
     * serves in-use decisions, and ContentScopeFilter hides the forms the caller may not see. Every form is checked,
     * in batches by id (keyset), each batch with only its own store links; only the matches are kept.
     *
     * @param callable $predicate
     * @return array
     * @phpstan-param callable(array<string, mixed>): bool $predicate
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     uid: string|null, component: string|null, store_ids: list<int>}>
     */
    public function findFormNodes(callable $predicate): array
    {
        $status = $this->featureChecker->getStatus(FeatureCheckerInterface::FORM_BUILDER);
        if (!$status['installed'] || !$status['enabled']) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('hyva_commerce_cms_form');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $storeTable = $this->resourceConnection->getTableName('hyva_commerce_cms_form_store');
        $hasStoreTable = $connection->isTableExists($storeTable);
        $batchSize = max(1, $this->formBatchSize);
        $matches = [];
        $lastId = 0;
        do {
            $select = $connection->select()
                ->from($table, ['id', 'identifier', 'title', 'draft_content', 'published_content'])
                ->where('id > ?', $lastId)
                ->order('id ASC')
                ->limit($batchSize);
            $rows = [];
            foreach ($connection->fetchAll($select) as $row) {
                $rows[] = $row;
            }
            if ($rows === []) {
                break;
            }
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = Cast::int($row['id']);
            }
            $lastId = max($ids);
            $storeIds = [];
            if ($hasStoreTable) {
                $links = $connection->fetchAll(
                    $connection->select()
                        ->from($storeTable, ['form_id', 'store_id'])
                        ->where('form_id IN (?)', $ids)
                );
                foreach ($links as $link) {
                    $storeIds[Cast::int($link['form_id'])][] = Cast::int($link['store_id']);
                }
            }
            foreach ($this->documentsOfRows(self::TYPE_FORM, $rows) as $entry) {
                if ($entry['document'] === null) {
                    continue;
                }
                $this->componentTree->walk($entry['document'], static function (array $node) use (
                    $predicate,
                    $entry,
                    $storeIds,
                    &$matches
                ): void {
                    if ($predicate($node)) {
                        $matches[] = [
                            'entity_type' => $entry['entity_type'],
                            'id' => $entry['id'],
                            'identifier' => $entry['identifier'],
                            'title' => $entry['title'],
                            'edition' => $entry['edition'],
                            'uid' => is_string($node['uid'] ?? null) ? $node['uid'] : null,
                            'component' => is_string($node['component'] ?? null) ? $node['component'] : null,
                            'store_ids' => $storeIds[$entry['id']] ?? [],
                        ];
                    }
                });
            }
            unset($rows, $storeIds);
        } while (count($ids) === $batchSize);
        return $matches;
    }

    /**
     * Magento (non-Hyvä) CMS content containing a needle.
     *
     * @param string $needle
     * @return array
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string}>
     */
    public function findInMagentoContent(string $needle): array
    {
        $matches = [];
        foreach ([ContentLocator::TYPE_PAGE, ContentLocator::TYPE_BLOCK] as $type) {
            $this->magentoRows[$type] ??= $this->contentIndex->getRows($type, true);
            foreach ($this->magentoRows[$type] as $row) {
                if (is_string($row['magento_content'] ?? null) && str_contains($row['magento_content'], $needle)) {
                    $matches[] = [
                        'entity_type' => $type,
                        'id' => Cast::int($row['id'] ?? 0),
                        'identifier' => Cast::string($row['identifier'] ?? ''),
                        'title' => Cast::string($row['title'] ?? ''),
                    ];
                }
            }
        }
        return $matches;
    }

    /**
     * Get menu rows.
     *
     * By default only for a caller allowed to view menus. With $ignoreMenuAcl the caller's menu ACL is not checked,
     * for in-use decisions (delete tools) that must count every reference; the caller then hides what it may not see.
     *
     * @param bool $ignoreMenuAcl
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    public function getMenuRows(bool $ignoreMenuAcl = false): array
    {
        // The table survives module:disable, but without the module no menu can be loaded or saved.
        if (!$this->isMenuBuilderReadable($ignoreMenuAcl)) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('hyva_commerce_cms_menu');
        if (!$connection->isTableExists($table)) {
            return [];
        }
        $rows = [];
        $select = $connection->select()->from(
            $table,
            ['id', 'identifier', 'title', 'is_active', 'draft_content', 'published_content']
        );
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Whether menus can be read: Menu Builder installed and enabled, and the caller may view them unless ignored.
     *
     * @param bool $ignoreMenuAcl
     * @return bool
     */
    private function isMenuBuilderReadable(bool $ignoreMenuAcl): bool
    {
        if (!$ignoreMenuAcl) {
            return $this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER);
        }
        $status = $this->featureChecker->getStatus(FeatureCheckerInterface::MENU_BUILDER);
        return $status['installed'] && $status['enabled'];
    }
}
