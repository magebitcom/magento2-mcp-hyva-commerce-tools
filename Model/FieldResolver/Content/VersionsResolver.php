<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * The ten most recent version-history items; null for a caller without the type's version_history permission.
 *
 * A get that names "versions" in `fields` is refused before it runs (VersionHistoryAcl::forFields); a get without
 * `fields` still answers, without the version history.
 */
class VersionsResolver implements ContentFieldResolverInterface
{
    public const KEY = 'versions';

    /**
     * @param ContentService $contentService
     * @param VersionHistoryAcl $versionHistoryAcl
     */
    public function __construct(
        private readonly ContentService $contentService,
        private readonly VersionHistoryAcl $versionHistoryAcl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return self::KEY;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return 70;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        if (!$this->versionHistoryAcl->isAllowed($entity['type'])) {
            return null;
        }
        return array_slice($this->contentService->getVersions($entity['type'], $entity['id']), 0, 10);
    }
}
