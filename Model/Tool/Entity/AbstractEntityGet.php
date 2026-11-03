<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Entity;

use Magebit\Mcp\Api\ContextualAclAwareInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\VersionHistoryAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content\IdentityResolver;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magebit\McpHyvaCommerceTools\Model\Tool\Schema\EntityTarget;

/**
 * Shared implementation of hyva_cms.page.get / hyva_cms.block.get; the response is composed by field resolvers.
 */
abstract class AbstractEntityGet extends AbstractTool implements ContextualAclAwareInterface
{
    use FlatFieldsTrait;

    public const ENTITY_TYPE = '';
    public const ENTITY_LABEL = '';

    /**
     * Keys of the identity group that fields also accepts on their own; they come back at the top level.
     */
    public const IDENTITY_FIELDS = ['type', 'id', 'identifier', 'title', 'is_active', 'store_ids', 'published_urls'];

    /**
     * @param ContentLocator $contentLocator
     * @param ResolverPipeline $pipeline
     * @param VersionHistoryAcl $versionHistoryAcl
     * @param ContentFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly ContentLocator $contentLocator,
        private readonly ResolverPipeline $pipeline,
        private readonly VersionHistoryAcl $versionHistoryAcl,
        private readonly array $fieldResolvers = []
    ) {
    }

    /**
     * Version history permission when `fields` asks for the versions key.
     *
     * @inheritDoc
     */
    public function getContextualAclResources(array $arguments): array
    {
        return $this->versionHistoryAcl->forFields(static::ENTITY_TYPE, $arguments);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Get Hyvä CMS ' . static::ENTITY_LABEL;
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $keys = [];
        foreach ($this->fieldResolvers as $resolver) {
            $keys[] = $resolver->getKey();
        }
        return sprintf(
            'Fetch one CMS %s with its Hyvä CMS content: status, outline, draft and published component trees, '
                . 'preview URLs, recent versions and images. Response keys (groups): %s. Use fields / exclude to '
                . 'narrow it: fields takes group names or the identity keys %s, which come back at the top level '
                . '(e.g. fields ["id", "title", "store_ids"]).',
            strtolower(static::ENTITY_LABEL),
            implode(', ', $keys),
            implode(', ', self::IDENTITY_FIELDS)
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->with(EntityTarget::fixed())
            ->array('fields', fn (ArrayBuilder $a) => $a->ofStrings()->description(
                'Response keys to return: group names or identity keys (id, identifier, title, store_ids, ...).'
            ))
            ->array('exclude', fn (ArrayBuilder $a) => $a->ofStrings()->description('Response keys to drop.'))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        $entity = $this->contentLocator->locate(static::ENTITY_TYPE, $arguments);
        $response = $this->resolveFields(
            $this->pipeline,
            $this->fieldResolvers,
            $entity,
            $arguments,
            $this->stringListArg($arguments, 'fields'),
            IdentityResolver::KEY,
            self::IDENTITY_FIELDS
        );
        return $this->jsonResult($response, ['entity_type' => static::ENTITY_TYPE, 'id' => $entity['id']]);
    }
}
