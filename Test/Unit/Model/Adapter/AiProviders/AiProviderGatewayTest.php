<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\AiProviders;

use Hyva\Ai\Api\ProviderInterface;
use Hyva\Ai\Api\ProviderPoolInterface;
use Hyva\Ai\Api\ProviderResolverInterface;
use Magebit\McpHyvaCommerceTools\Model\Adapter\AiProviders\AiProviderGateway;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AiProviderGatewayTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface $config;

    /**
     * @var array<string, string> Config values by path
     */
    private array $values = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(ScopeConfigInterface::class);
        $this->config->method('getValue')->willReturnCallback(fn (string $path) => $this->values[$path] ?? null);
    }

    public function testProvidersCombineConfiguredFlagLabelAndModels(): void
    {
        $data = $this->gateway()->providers();
        $this->assertSame([
            ['name' => 'anthropic', 'label' => 'Anthropic (Claude)', 'configured' => true,
                'models' => ['claude-sonnet-5']],
            ['name' => 'gemini', 'label' => 'gemini', 'configured' => false, 'models' => []],
        ], $data);
    }

    public function testNoApiKeyConfigPathIsRead(): void
    {
        $paths = [];
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(function (string $path) use (&$paths) {
            $paths[] = $path;
            return 'claude-sonnet-5';
        });
        $gateway = $this->gateway($config);
        $gateway->providers();
        $gateway->translationSettings();
        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('api_key', $path);
        }
    }

    public function testTranslationSettingsResolveTheProvider(): void
    {
        $this->values = [
            'hyva_ai/hyva_cms_translations/enabled' => '1',
            'hyva_ai/hyva_cms_translations/model' => 'claude-sonnet-5',
        ];
        $this->assertSame(
            ['enabled' => true, 'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_configured' => true],
            $this->gateway()->translationSettings()
        );
    }

    public function testUnresolvableModelGivesNoProvider(): void
    {
        $this->values = ['hyva_ai/hyva_cms_translations/model' => 'gone-model'];
        $this->assertSame(
            ['enabled' => false, 'model' => 'gone-model', 'provider' => null, 'provider_configured' => false],
            $this->gateway()->translationSettings()
        );
    }

    public function testEmptyModelIsNotResolved(): void
    {
        $this->assertNull($this->gateway()->translationSettings()['provider']);
    }

    private function gateway(?ScopeConfigInterface $config = null): AiProviderGateway
    {
        $anthropic = $this->createMock(ProviderInterface::class);
        $anthropic->method('isConfigured')->willReturn(true);
        $gemini = $this->createMock(ProviderInterface::class);
        $gemini->method('isConfigured')->willReturn(false);
        $pool = $this->createMock(ProviderPoolInterface::class);
        $pool->method('getAll')->willReturn(['anthropic' => $anthropic, 'gemini' => $gemini]);
        $pool->method('has')->willReturnCallback(fn (string $n): bool => in_array($n, ['anthropic', 'gemini'], true));
        $pool->method('get')->willReturnCallback(fn (string $n) => $n === 'anthropic' ? $anthropic : $gemini);
        $resolver = $this->createMock(ProviderResolverInterface::class);
        $resolver->method('getProviderConfigurations')->willReturn([
            'anthropic' => ['label' => 'anthropic', 'display_label' => 'Anthropic (Claude)',
                'options' => ['claude-sonnet-5' => ['value' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5']]],
        ]);
        $resolver->method('resolveProviderFromModel')->willReturnCallback(
            fn (string $m): string => $m === 'claude-sonnet-5'
                ? 'anthropic'
                : throw new LocalizedException(__('Could not determine provider'))
        );
        return new AiProviderGateway(
            $this->optionalServices([
                ProviderPoolInterface::class => $pool,
                ProviderResolverInterface::class => $resolver,
            ]),
            $config ?? $this->config
        );
    }
}
