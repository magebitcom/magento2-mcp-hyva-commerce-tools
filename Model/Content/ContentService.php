<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Content;

use Hyva\CmsLiveviewEditor\Model\Security\ContentDocumentGuard;
use Hyva\CmsConnect\Exception\ConflictException;
use Hyva\CmsLiveviewEditor\Model\Image\ImageIdBackfiller;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Hyva\CmsLiveviewEditor\Model\JsonSchema;
use Hyva\CmsLiveviewEditor\Model\Provider as ContentProvider;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\SecurityViolationException;
use Magento\Framework\Model\AbstractModel;

/**
 * Reads and writes Hyvä CMS content the same way the Liveview editor does
 * (stamp `version` → validate → Provider::saveContent → Provider::saveVersionHistoryItem).
 * Drafts are always saved with publish=false; publish() is the only publishing path.
 */
class ContentService
{
    private const DATE_FORMAT = 'Y-m-d\TH:i:sP';

    /**
     * @param ContentProvider $contentProvider
     * @param JsonSchema $jsonSchema
     * @param ComponentTree $componentTree
     * @param CallerContext $callerContext
     * @param ImageIdBackfiller $imageIdBackfiller
     * @param ContentDocumentGuard $contentDocumentGuard
     */
    public function __construct(
        private readonly ContentProvider $contentProvider,
        private readonly JsonSchema $jsonSchema,
        private readonly ComponentTree $componentTree,
        private readonly CallerContext $callerContext,
        private readonly ImageIdBackfiller $imageIdBackfiller,
        private readonly ContentDocumentGuard $contentDocumentGuard
    ) {
    }

    /**
     * Current draft, falling back to the published content, then to an empty document.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @throws LocalizedException
     * @phpstan-return array<string, mixed>
     */
    public function getWorkingDocument(string $type, int $id): array
    {
        $draft = $this->contentProvider->getDraftContentJson($type, $id);
        if ($this->hasContent($draft)) {
            return $this->componentTree->parse($draft, $type, $id);
        }
        return $this->componentTree->parse($this->contentProvider->getPublishedContentJson($type, $id), $type, $id);
    }

    /**
     * Get documents.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{draft: array<string, mixed>|null, published: array<string, mixed>|null}
     */
    public function getDocuments(string $type, int $id): array
    {
        $draft = $this->contentProvider->getDraftContentJson($type, $id);
        $published = $this->contentProvider->getPublishedContentJson($type, $id);
        return [
            'draft' => $this->hasContent($draft) ? $this->componentTree->parse($draft, $type, $id) : null,
            'published' => $this->hasContent($published) ? $this->componentTree->parse($published, $type, $id) : null,
        ];
    }

    /**
     * Schema + structural validation without saving.
     *
     * @param array $document
     * @return array
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array{valid: bool, schema_error: string|null, issues: list<array<string, mixed>>}
     */
    public function validate(array $document): array
    {
        $stamped = $this->stamp($document, 'draft');
        $json = $this->componentTree->encode($stamped);
        $schema = $this->jsonSchema->validateContentGroup($json);
        $schemaError = empty($schema['isValid'])
            ? (
                is_string($schema['message'] ?? null) ? $schema['message'] : 'Content does not match the Hyvä CMS '
                    . 'JSON schema.'
            )
            : null;
        $issues = $this->componentTree->validate($stamped);
        try {
            // Hyvä 1.4 repository guard: 3 MB, 5000 nodes, depth 50, uid format (refused on save otherwise).
            $this->contentDocumentGuard->assertValidJson($json);
        } catch (SecurityViolationException $e) {
            $issues[] = [
                'severity' => 'error',
                'code' => 'content_limit',
                'uid' => null,
                'component' => null,
                'message' => $e->getMessage(),
            ];
        }
        $errors = array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error');

        return [
            'valid' => $schemaError === null && $errors === [],
            'schema_error' => $schemaError,
            'issues' => $issues,
        ];
    }

    /**
     * Save a document as the entity's draft. Never touches published content.
     *
     * @param string $type
     * @param int $id
     * @param array $document
     * @param string|null $versionName
     * @param string|null $baseVersionUid Draft version uid the caller edited; refused when the draft has moved on.
     * @return array
     * @throws LocalizedException When the document is invalid or saving fails.
     * @throws ConflictException When the stored draft is no longer $baseVersionUid.
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array{version_id: int|null, version_uid: string, warnings: list<array<string, mixed>>}
     */
    public function saveDraft(
        string $type,
        int $id,
        array $document,
        ?string $versionName = null,
        ?string $baseVersionUid = null
    ): array {
        $this->assertBaseVersion($type, $id, $baseVersionUid);
        // Provider::saveContent() does not resolve Media Gallery asset ids (only the editor does), so do it here.
        $document = $this->imageIdBackfiller->backfill($this->componentTree->markImageFields($document));
        $document = $this->componentTree->normalizeDocument($document, $type, $id);
        $validation = $this->validate($document);
        if (!$validation['valid']) {
            throw new LocalizedException(__('The content was not saved: %1', $this->describeErrors($validation)));
        }

        $this->snapshotUnversionedDraft($type, $id);

        $document = $this->stamp($document, 'draft', $versionName);
        $json = $this->componentTree->encode($document);
        if ($this->contentProvider->saveContent($type, $id, $json, false) === null) {
            throw new LocalizedException(__('Hyvä CMS refused to save the %1 #%2 draft.', $type, $id));
        }
        $version = $this->contentProvider->saveVersionHistoryItem($type, $id, $json, false);
        $this->applyVersionName($type, $id, $version, $versionName);

        $warnings = array_values(
            array_filter($validation['issues'], static fn (array $i): bool => $i['severity'] !== 'error')
        );
        $versionData = is_array($document['version'] ?? null) ? $document['version'] : [];
        return [
            'version_id' => $this->versionId($version),
            'version_uid' => is_string($versionData['uid'] ?? null) ? $versionData['uid'] : '',
            'warnings' => $warnings,
        ];
    }

    /**
     * Publish the current draft, or a specific version-history item.
     *
     * @param string $type
     * @param int $id
     * @param int|null $versionId
     * @return array
     * @throws LocalizedException
     * @phpstan-return array{version_id: int|null, version_uid: string, source: string}
     */
    public function publish(string $type, int $id, ?int $versionId = null): array
    {
        if ($versionId !== null) {
            $versionJson = $this->getVersionData($type, $id, $versionId);
            $document = $this->componentTree->parse($versionJson, $type, $id);
            $source = 'version:' . $versionId;
        } else {
            $draft = $this->contentProvider->getDraftContentJson($type, $id);
            if (!$this->hasContent($draft)) {
                throw new LocalizedException(__('%1 #%2 has no draft to publish.', $type, $id));
            }
            $document = $this->componentTree->parse($draft, $type, $id);
            $source = 'draft';
        }

        // A draft or version saved before a component was removed can fail the structural checks saveDraft() applies.
        $validation = $this->validate($document);
        if (!$validation['valid']) {
            throw new LocalizedException(__('Not published: %1', $this->describeErrors($validation)));
        }

        $document = $this->stamp($document, 'published');
        $json = $this->componentTree->encode($document);
        if ($this->contentProvider->saveContent($type, $id, $json, true) === null) {
            throw new LocalizedException(__('Hyvä CMS refused to publish %1 #%2.', $type, $id));
        }
        $version = $this->contentProvider->saveVersionHistoryItem($type, $id, $json, true);
        $versionData = is_array($document['version'] ?? null) ? $document['version'] : [];

        return [
            'version_id' => $this->versionId($version),
            'version_uid' => is_string($versionData['uid'] ?? null) ? $versionData['uid'] : '',
            'source' => $source,
        ];
    }

    /**
     * Get versions.
     *
     * @param string $type
     * @param int $id
     * @return array
     * @phpstan-return list<array{version_id: int, name: string, state: mixed, was_published: bool, pinned: bool,
     *     author: mixed, timestamp: mixed, created_at: mixed, restored_from: mixed, published_by_schedule: bool,
     *     root_components: int}>
     */
    public function getVersions(string $type, int $id): array
    {
        $versions = [];
        foreach ($this->contentProvider->getVersionHistory($type, $id) as $item) {
            if (!$item instanceof AbstractModel) {
                continue;
            }
            $data = json_decode(Cast::string($item->getData('version_data')), true);
            $version = is_array($data) && is_array($data['version'] ?? null) ? $data['version'] : [];
            $content = is_array($data) && is_array($data['content'] ?? null) ? $data['content'] : [];
            $versions[] = [
                'version_id' => Cast::int($item->getData('id')),
                'name' => Cast::string($item->getData('name')),
                'state' => $version['version_state'] ?? null,
                'was_published' => Cast::bool($item->getData('was_published')),
                'pinned' => Cast::bool($item->getData('pinned')),
                'author' => $version['author'] ?? null,
                'timestamp' => $version['timestamp'] ?? null,
                'created_at' => $item->getData('creation_time'),
                'restored_from' => is_array($version['restored_from'] ?? null) ? (
                    $version['restored_from']['version_id'] ?? null
                ) : null,
                'published_by_schedule' => (bool) ($version['published_by_schedule'] ?? false),
                'root_components' => count($content),
            ];
        }
        return $versions;
    }

    /**
     * Get version data.
     *
     * @param string $type
     * @param int $id
     * @param int $versionId
     * @return string
     * @throws LocalizedException
     */
    public function getVersionData(string $type, int $id, int $versionId): string
    {
        $items = $this->contentProvider->getVersionHistory($type, $id, $versionId);
        $item = $items[0] ?? null;
        if (!$item instanceof AbstractModel) {
            throw new LocalizedException(__('Version #%1 does not exist for %2 #%3.', $versionId, $type, $id));
        }
        return Cast::string($item->getData('version_data'));
    }

    /**
     * Admin username of the MCP caller, used as the version author.
     *
     * @return string
     */
    public function getAuthor(): string
    {
        $name = $this->callerContext->adminUsername();
        return $name !== null ? $name . ' (MCP)' : 'MCP';
    }

    /**
     * Mirror of the editor's Versioning trait.
     *
     * @param array $document
     * @param string $state edit|draft|published
     * @param string|null $name
     * @return array
     * @phpstan-param array<string, mixed> $document
     * @phpstan-return array<string, mixed>
     */
    public function stamp(array $document, string $state, ?string $name = null): array
    {
        $now = date(self::DATE_FORMAT);
        $previous = is_array($document['version'] ?? null) ? $document['version'] : [];
        $version = array_merge([
            'uid' => null,
            'author' => '',
            'version_state' => '',
            'content_type' => '',
            'timestamp' => '',
            'last_edit_timestamp' => '',
            'last_save_timestamp' => '',
            'last_publish_timestamp' => '',
            'restored_to' => [],
            'restored_from' => null,
        ], $previous);

        // Hyvä's own restore leaves last_publish_timestamp null for never-published content; its JSON schema
        // requires strings, so a later publish would be refused.
        foreach (['timestamp', 'last_edit_timestamp', 'last_save_timestamp', 'last_publish_timestamp'] as $key) {
            $version[$key] = is_string($version[$key] ?? null) ? $version[$key] : '';
        }
        $version['uid'] = uniqid();
        $version['author'] = $this->getAuthor();
        $version['version_state'] = $state;
        $version['content_type'] = is_string($document['contentType'] ?? null) ? $document['contentType'] : '';
        $version['timestamp'] = $now;
        $version['last_edit_timestamp'] = $now;
        $version['restored_from'] = null;
        $version['published_by_schedule'] = null;
        if ($state !== 'edit') {
            $version['last_save_timestamp'] = $now;
        }
        if ($state === 'published') {
            $version['last_publish_timestamp'] = $now;
        }
        if ($name !== null) {
            $version['name'] = $name;
        }
        $document['version'] = $version;
        return $document;
    }

    /**
     * Keep a draft that never went through version history (e.g. written by an import) restorable.
     *
     * @param string $type
     * @param int $id
     * @return void
     */
    private function snapshotUnversionedDraft(string $type, int $id): void
    {
        $draft = $this->contentProvider->getDraftContentJson($type, $id);
        if ($this->hasContent($draft) && $this->contentProvider->getVersionHistory($type, $id) === []) {
            $this->contentProvider->saveVersionHistoryItem($type, $id, $draft, false);
        }
    }

    /**
     * Apply version name.
     *
     * @param string $type
     * @param int $id
     * @param object|null $version
     * @param string|null $name
     * @return void
     */
    private function applyVersionName(string $type, int $id, ?object $version, ?string $name): void
    {
        $versionId = $this->versionId($version);
        if ($name !== null && $name !== '' && $versionId !== null) {
            $this->contentProvider->updateVersionProperties(
                $type,
                $id,
                $versionId,
                ['name' => mb_substr($name, 0, 100)]
            );
        }
    }

    /**
     * Version id.
     *
     * @param object|null $version
     * @return int|null
     */
    private function versionId(?object $version): ?int
    {
        return $version instanceof AbstractModel && $version->getData('id') ? Cast::int($version->getData('id')) : null;
    }

    /**
     * Describe errors.
     *
     * @param array $validation
     * @return string
     * @phpstan-param array{valid: bool, schema_error: string|null, issues: list<array<string, mixed>>} $validation
     */
    private function describeErrors(array $validation): string
    {
        $messages = [];
        if ($validation['schema_error'] !== null) {
            $messages[] = $validation['schema_error'];
        }
        foreach ($validation['issues'] as $issue) {
            if ($issue['severity'] === 'error') {
                $messages[] = sprintf(
                    '[%s] %s',
                    is_string($issue['uid'] ?? null) ? $issue['uid'] : '-',
                    is_string($issue['message'] ?? null) ? $issue['message'] : ''
                );
            }
        }
        return implode('; ', array_slice($messages, 0, 10));
    }

    /**
     * Has content.
     *
     * @param string $json
     * @return bool
     */
    private function hasContent(string $json): bool
    {
        return trim($json) !== '' && trim($json) !== '[]';
    }

    /**
     * Refuse a save based on a draft that changed since the caller read it (same rule as the Hyvä editor).
     *
     * @param string $type
     * @param int $id
     * @param string|null $baseVersionUid
     * @return void
     * @throws ConflictException
     */
    public function assertBaseVersion(string $type, int $id, ?string $baseVersionUid): void
    {
        if ($baseVersionUid === null || $baseVersionUid === '') {
            return;
        }
        $current = json_decode($this->contentProvider->getDraftContentJson($type, $id), true);
        $currentUid = is_array($current) && is_array($current['version'] ?? null)
            && is_string($current['version']['uid'] ?? null) ? $current['version']['uid'] : null;
        if ($currentUid !== null && !hash_equals($currentUid, $baseVersionUid)) {
            throw new ConflictException(sprintf(
                'The %s #%d draft was saved elsewhere since you read it (base version %s, current %s).',
                $type,
                $id,
                $baseVersionUid,
                $currentUid
            ));
        }
    }
}
