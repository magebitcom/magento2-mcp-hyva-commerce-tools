<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\FieldResolver\Menu;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Api\Adapter\MenuGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\ContentFieldResolverInterface;

/**
 * Menu settings: title, identifier, store views, active flag, preview key, draft/published state.
 */
class MenuSettingsResolver implements ContentFieldResolverInterface
{
    public const KEY = 'settings';

    /**
     * @param MenuGatewayInterface $menuGateway
     * @param PreviewService $previewService
     */
    public function __construct(
        private readonly MenuGatewayInterface $menuGateway,
        private readonly PreviewService $previewService
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
        return 10;
    }

    /**
     * @inheritDoc
     */
    public function resolve(array $entity, array $args): mixed
    {
        unset($args);
        return $this->menuGateway->get($entity['id'])
            + ['published_urls' => $this->previewService->getPublishedUrls(ContentLocator::TYPE_MENU, $entity['id'])];
    }
}
