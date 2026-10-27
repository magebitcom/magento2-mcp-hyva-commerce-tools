<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Translation;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\TranslationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\TranslationScanner;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for the Hyvä CMS AI Translations tools (translations stored inline in the content, per store view).
 *
 * @phpstan-import-type TextRow from TranslationScanner
 */
abstract class AbstractTranslationTool extends AbstractContentTool
{
    /**
     * @param ContentLocator $contentLocator
     * @param ContentService $contentService
     * @param ContentAcl $contentAcl
     * @param FeatureCheckerInterface $featureChecker
     * @param TranslationGatewayInterface $translations
     * @param TranslationScanner $scanner
     */
    public function __construct(
        protected readonly ContentLocator $contentLocator,
        protected readonly ContentService $contentService,
        protected readonly ContentAcl $contentAcl,
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly TranslationGatewayInterface $translations,
        protected readonly TranslationScanner $scanner
    ) {
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        if (!$this->featureChecker->isAvailable(FeatureCheckerInterface::CMS_AI_TRANSLATIONS)) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::CMS_AI_TRANSLATIONS)
            );
        }
        return $this->executeTranslation($arguments);
    }

    /**
     * Tool body once AI Translations is available.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeTranslation(array $arguments): ToolResultInterface;

    /**
     * Store views that get translations: a target language that differs from the content language.
     *
     * @param array $settings
     * @param array $onlyStoreIds
     * @return array
     * @phpstan-param array{default_language: string, stores: list<array{store_id: int, code: string,
     *     store_locale: string, target_locale: string|null}>} $settings
     * @phpstan-param list<int> $onlyStoreIds
     * @phpstan-return list<array{store_id: int, code: string, target_locale: string}>
     */
    protected function targetStores(array $settings, array $onlyStoreIds): array
    {
        $targets = [];
        foreach ($settings['stores'] as $store) {
            $locale = $store['target_locale'];
            if ($locale === null || $locale === '' || $locale === $settings['default_language']
                || ($onlyStoreIds !== [] && !in_array($store['store_id'], $onlyStoreIds, true))) {
                continue;
            }
            $targets[] = ['store_id' => $store['store_id'], 'code' => $store['code'], 'target_locale' => $locale];
        }
        return $targets;
    }

    /**
     * @inheritDoc
     */
    protected function contentAcl(): ContentAcl
    {
        return $this->contentAcl;
    }
}
