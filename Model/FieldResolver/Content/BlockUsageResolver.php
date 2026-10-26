<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Where a CMS block is used: Hyvä cms_block components, widget components, Magento content and widget instances.
 */
class BlockUsageResolver implements ContentFieldResolverInterface
{
    public const KEY = 'usage';

    /**
     * @param UsageFinder $usageFinder
     */
    public function __construct(
        private readonly UsageFinder $usageFinder
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
        return 90;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $this->usageFinder->findBlockUsage($entity['id'], $entity['identifier']);
    }
}
