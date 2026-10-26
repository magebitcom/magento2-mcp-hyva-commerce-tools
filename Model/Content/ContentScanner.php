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
 * Menus are read with plain SQL so this class works without Hyva_MenuBuilder.
 */
class ContentScanner
{
    public const EDITION_DRAFT = 'draft';
    public const EDITION_PUBLISHED = 'published';

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
     */
    public function __construct(
        private readonly ContentIndex $contentIndex,
        private readonly ComponentTree $componentTree,
        private readonly ResourceConnection $resourceConnection,
        private readonly FeatureCheckerInterface $featureChecker
    ) {
    }

    /**
     * Get documents.
     *
     * @param array $types
     * @param bool $includeDraft
     * @param bool $includePublished
     * @return array
     * @phpstan-param list<string> $types
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     document: array<string, mixed>|null, error: string|null}>
     */
    public function getDocuments(
        array $types = ContentLocator::TYPES,
        bool $includeDraft = true,
        bool $includePublished = true
    ): array {
        $documents = [];
        foreach ($types as $type) {
            $rows = $type === ContentLocator::TYPE_MENU
                ? $this->getMenuRows()
                : $this->contentIndex->getRows($type, true);
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
        }
        return $documents;
    }

    /**
     * Nodes matching a predicate, with their location.
     *
     * @param callable $predicate
     * @param array $types
     * @return array
     * @phpstan-param callable(array<string, mixed>): bool $predicate
     * @phpstan-param list<string> $types
     * @phpstan-return list<array{entity_type: string, id: int, identifier: string, title: string, edition: string,
     *     uid: string|null, component: string|null}>
     */
    public function findNodes(callable $predicate, array $types = ContentLocator::TYPES): array
    {
        $matches = [];
        foreach ($this->getDocuments($types) as $entry) {
            if ($entry['document'] === null) {
                continue;
            }
            $this->componentTree->walk($entry['document'], function (array $node) use (
                $predicate,
                $entry,
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
                        'menu_identifier' => is_string($node['menu_identifier'] ?? null)
                            ? $node['menu_identifier']
                            : null,
                    ];
                }
            });
        }
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
     * @return array
     * @phpstan-return list<array<string, mixed>>
     */
    public function getMenuRows(): array
    {
        // The table survives module:disable, but without the module no menu can be loaded or saved.
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::MENU_BUILDER)) {
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
}
