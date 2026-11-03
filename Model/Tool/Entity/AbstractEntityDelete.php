<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;
use Magento\Framework\Exception\AuthorizationException;

/**
 * hyva_cms.page.delete / hyva_cms.block.delete: confirm-gated delete of one CMS page or block.
 *
 * Three permissions: the tool's own resource ("Delete CMS pages via MCP", granted to no role by default), the
 * Magento admin delete permission (ADMIN_DELETE_RESOURCE) and Hyvä's delete verb for the type. The core checks the
 * tool resource and the listing resource (the first of the other two the role lacks), and run() checks both again.
 * The store scope write rule applies: every store view the entity is assigned to must be the role's.
 */
abstract class AbstractEntityDelete extends AbstractTool
{
    public const IS_WRITE = true;
    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';

    /**
     * Permission the Magento admin delete action needs (its ADMIN_RESOURCE).
     */
    public const ADMIN_DELETE_RESOURCE = '';

    /**
     * Hyvä CMS 1.4 delete verb of the type (ContentTypeAuthorization, e.g. Hyva_Cms::page_delete).
     */
    public const HYVA_DELETE_RESOURCE = '';

    /**
     * @param ContentLocator $contentLocator
     * @param ContentAcl $contentAcl
     * @param CallerContext $callerContext
     * @param DeletionUsage $deletionUsage
     * @param EntityWriter $entityWriter
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ContentAcl $contentAcl,
        private readonly CallerContext $callerContext,
        private readonly DeletionUsage $deletionUsage,
        private readonly EntityWriter $entityWriter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Delete Hyvä CMS ' . static::ENTITY_LABEL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $what = 'CMS ' . strtolower(static::ENTITY_LABEL);
        $usage = static::ENTITY_TYPE === ContentLocator::TYPE_PAGE
            ? 'home / 404 / cookies page configuration, links in Hyvä CMS content and menus'
            : 'Hyvä CMS components, widget directives in Magento content, widget instances';
        return sprintf(
            'Delete a %1$s permanently, from every store view it is assigned to, with its Hyvä drafts, version '
                . 'history and Tailwind CSS (scheduled releases that name it are not removed). Without "confirm": '
                . 'true nothing is deleted and the reply previews the %1$s, its store views and where it is still '
                . 'used (%2$s); a %1$s in use stays deletable, but say so to the user before confirming. Needs the '
                . 'MCP permission for this tool plus the admin delete permissions %3$s and %4$s. A role restricted '
                . 'to some websites can delete only a %1$s whose store views are all its own. To hide it instead, '
                . 'set settings.is_active=false with hyva_cms_%5$s_update.',
            $what,
            $usage,
            static::ADMIN_DELETE_RESOURCE,
            static::HYVA_DELETE_RESOURCE,
            strtolower(static::ENTITY_LABEL)
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Delete, after reviewing the preview. Without it nothing is deleted.'
            ))
            ->toArray();
    }

    /**
     * Listed with the first of the admin and Hyvä delete permissions the role lacks, else Hyvä's
     *
     * The core checks this resource on tools/list and tools/call, so a role without either permission neither sees
     * nor runs the tool. Without an MCP caller (CLI) the admin permission is returned.
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->contentAcl->listingResource(
            [static::ENTITY_TYPE],
            [ContentAcl::VERB_DELETE],
            [static::ADMIN_DELETE_RESOURCE],
            static::ADMIN_DELETE_RESOURCE
        );
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $this->assertDeletePermissions();
        $entity = $this->contentLocator->locateForWrite(static::ENTITY_TYPE, $arguments);
        $usage = static::ENTITY_TYPE === ContentLocator::TYPE_PAGE
            ? $this->deletionUsage->forPage($entity['id'], $entity['identifier'], $entity['store_ids'])
            : $this->deletionUsage->forBlock($entity['id'], $entity['identifier']);
        $inUse = ($usage['used'] ?? false) === true;
        $summary = [
            'entity_type' => $entity['type'],
            'id' => $entity['id'],
            'identifier' => $entity['identifier'],
            'title' => $entity['title'],
            'is_active' => $entity['is_active'],
            'store_ids' => $entity['store_ids'],
            'in_use' => $inUse,
        ];
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                'delete_' . static::ENTITY_TYPE,
                [$summary],
                ['in_use' => $inUse, 'usage' => $usage],
                null,
                sprintf(
                    'Deleting removes the %1$s from every store view listed, with its Hyvä drafts and version '
                        . 'history; it cannot be undone (hyva_cms_export keeps a copy).',
                    strtolower(static::ENTITY_LABEL)
                ) . ($inUse
                    ? ' It is still in use (details.usage): those references break. Tell the user before confirming.'
                    : '')
            );
        }
        $this->entityWriter->delete($entity['type'], $entity['id']);
        return $this->jsonResult(
            ['deleted' => true] + $summary,
            ['entity_type' => $entity['type'], 'id' => $entity['id'], 'deleted' => true, 'in_use' => $inUse]
        );
    }

    /**
     * Refuse a role without the admin or Hyvä delete permission (the core gate checks one of them per role)
     *
     * @return void
     * @throws AuthorizationException
     */
    private function assertDeletePermissions(): void
    {
        foreach ([static::ADMIN_DELETE_RESOURCE, static::HYVA_DELETE_RESOURCE] as $resource) {
            if ($this->callerContext->isAllowed($resource) === false) {
                throw new AuthorizationException(__(
                    'Your admin role may not delete %1s: it needs the %2 permission.',
                    'CMS ' . strtolower(static::ENTITY_LABEL),
                    $resource
                ));
            }
        }
    }
}
