<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Adapter\AiTranslations;

use Magento\Framework\Exception\LocalizedException;
use Magebit\McpHyvaCommerceTools\Model\Adapter\OptionalServiceFactory;
use Hyva\CmsAiTranslations\Api\ConfigInterface;
use Hyva\CmsAiTranslations\Model\Handler\TranslationHandler;
use Hyva\CmsAiTranslations\Model\TranslationProvider;
use Magebit\McpHyvaCommerceTools\Api\Adapter\TranslationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adapter over Hyvä CMS AI Translations 1.0.x: the same chain as its ProcessBatch controller, without the batch
 * cache (TranslationProvider → TranslationHandler::getData → provider::process → processResponse).
 */
class TranslationGateway implements TranslationGatewayInterface
{
    /**
     * @param OptionalServiceFactory $optionalServices
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly OptionalServiceFactory $optionalServices,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function settings(): array
    {
        $stores = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            $stores[] = [
                'store_id' => $storeId,
                'code' => (string) $store->getCode(),
                'store_locale' => $store instanceof Store
                    ? Cast::string($store->getConfig(ConfigInterface::XPATH_MAGENTO_LOCALE))
                    : '',
                'target_locale' => $this->config()->getTargetLocaleForStore((string) $storeId),
            ];
        }
        return [
            'enabled' => $this->config()->isAiTranslationsEnabled(),
            'model' => Cast::string($this->translationHandler()->getOptions()['model'] ?? ''),
            'default_language' => $this->config()->getDefaultContentLanguage(),
            'stores' => $stores,
        ];
    }

    /**
     * @inheritDoc
     */
    public function translate(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $provider = $this->translationProvider()->get();
        $data = $this->translationHandler()->getData($items);
        $response = $provider->process($data, $this->translationHandler()->getOptions());
        $translations = [];
        foreach ($this->translationHandler()->processResponse($response, $data) as $key => $text) {
            $translations[(string) $key] = Cast::string($text);
        }
        return $translations;
    }

    /**
     * ConfigInterface from the optional package, created on first use.
     *
     * @return ConfigInterface
     * @throws LocalizedException When the package is not installed.
     */
    private function config(): ConfigInterface
    {
        return $this->optionalServices->get(ConfigInterface::class);
    }

    /**
     * TranslationProvider from the optional package, created on first use.
     *
     * @return TranslationProvider
     * @throws LocalizedException When the package is not installed.
     */
    private function translationProvider(): TranslationProvider
    {
        return $this->optionalServices->get(TranslationProvider::class);
    }

    /**
     * TranslationHandler from the optional package, created on first use.
     *
     * @return TranslationHandler
     * @throws LocalizedException When the package is not installed.
     */
    private function translationHandler(): TranslationHandler
    {
        return $this->optionalServices->get(TranslationHandler::class);
    }
}
