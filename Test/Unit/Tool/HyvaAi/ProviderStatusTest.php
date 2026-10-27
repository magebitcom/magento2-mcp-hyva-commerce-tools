<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaAi;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiProviderGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magebit\McpHyvaCommerceTools\Tool\HyvaAi\Provider\ProviderStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderStatusTest extends TestCase
{
    /**
     * @var AiProviderGatewayInterface&MockObject
     */
    private AiProviderGatewayInterface $gateway;

    /**
     * @var array<string, bool>
     */
    private array $features = [
        FeatureCheckerInterface::AI_PROVIDERS => true,
        FeatureCheckerInterface::CMS_AI_TRANSLATIONS => true,
    ];

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(AiProviderGatewayInterface::class);
        $this->gateway->method('providers')->willReturn([
            ['name' => 'anthropic', 'label' => 'Anthropic (Claude)', 'configured' => true,
                'models' => ['claude-sonnet-5', 'claude-opus-5']],
            ['name' => 'gemini', 'label' => 'Google Gemini', 'configured' => false, 'models' => ['gemini-pro']],
        ]);
    }

    public function testListsConfiguredAndUnconfiguredProviders(): void
    {
        $this->gateway->method('translationSettings')->willReturn($this->translation());
        $data = $this->payload();
        $this->assertSame('anthropic', $this->arr($this->arr($data['providers'])[0])['name']);
        $this->assertTrue($this->arr($this->arr($data['providers'])[0])['configured']);
        $first = $this->arr($this->arr($data['providers'])[0]);
        $this->assertSame(['claude-sonnet-5', 'claude-opus-5'], $first['models']);
        $this->assertFalse($this->arr($this->arr($data['providers'])[1])['configured']);
    }

    public function testTranslationIsReadyWhenEnabledWithAConfiguredProvider(): void
    {
        $this->gateway->method('translationSettings')->willReturn($this->translation());
        $this->assertSame([
            'enabled' => true, 'model' => 'claude-sonnet-5', 'provider' => 'anthropic',
            'provider_configured' => true, 'ready' => true,
        ], $this->arr($this->payload()['cms_translation']));
    }

    public function testTranslationIsNotReadyWithAnUnconfiguredProvider(): void
    {
        $this->gateway->method('translationSettings')->willReturn(
            $this->translation(['model' => 'gemini-pro', 'provider' => 'gemini', 'provider_configured' => false])
        );
        $translation = $this->arr($this->payload()['cms_translation']);
        $this->assertFalse($translation['provider_configured']);
        $this->assertFalse($translation['ready']);
    }

    public function testTranslationIsNotReadyWhenDisabled(): void
    {
        $this->gateway->method('translationSettings')->willReturn($this->translation(['enabled' => false]));
        $translation = $this->arr($this->payload()['cms_translation']);
        $this->assertTrue($translation['provider_configured']);
        $this->assertFalse($translation['ready']);
    }

    public function testUnresolvableModelHasNoProvider(): void
    {
        $this->gateway->method('translationSettings')->willReturn(
            $this->translation(['model' => 'gone-model', 'provider' => null, 'provider_configured' => false])
        );
        $translation = $this->arr($this->payload()['cms_translation']);
        $this->assertNull($translation['provider']);
        $this->assertFalse($translation['ready']);
    }

    public function testWithoutTheTranslationModuleTheSectionIsNull(): void
    {
        $this->features[FeatureCheckerInterface::CMS_AI_TRANSLATIONS] = false;
        $this->gateway->expects($this->never())->method('translationSettings');
        $data = $this->payload();
        $this->assertNull($data['cms_translation']);
        $this->assertCount(2, $this->arr($data['providers']));
    }

    public function testAbsentFeatureReturnsUnavailable(): void
    {
        $this->features[FeatureCheckerInterface::AI_PROVIDERS] = false;
        $this->gateway->expects($this->never())->method('providers');
        $data = $this->payload();
        $this->assertFalse($data['available']);
        $this->assertSame(FeatureCheckerInterface::AI_PROVIDERS, $data['feature']);
    }

    public function testNoKeyMaterialIsReturned(): void
    {
        $this->gateway->method('translationSettings')->willReturn($this->translation());
        $text = $this->text($this->tool()->execute([]));
        $this->assertStringNotContainsString('api_key', $text);
        $this->assertStringNotContainsString('sk-', $text);
    }

    public function testToolIsReadOnlyAndUsesTheAiConfigResource(): void
    {
        $tool = $this->tool();
        $this->assertSame('hyva_ai.provider.status', $tool->getName());
        $this->assertSame('Hyva_Ai::config', $tool->getUnderlyingAclResource());
        $this->assertFalse($tool->getConfirmationRequired());
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function translation(array $override = []): array
    {
        return $override + [
            'enabled' => true, 'model' => 'claude-sonnet-5', 'provider' => 'anthropic', 'provider_configured' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $data = json_decode($this->text($this->tool()->execute([])), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @param mixed $value
     * @return array<mixed>
     */
    private function arr(mixed $value): array
    {
        $this->assertIsArray($value);
        return $value;
    }

    /**
     * @param ToolResultInterface $result
     * @return string
     */
    private function text(ToolResultInterface $result): string
    {
        $content = $result->getContent();
        return Cast::string($this->arr($content[0] ?? [])['text'] ?? '');
    }

    private function tool(): ProviderStatus
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturnCallback(fn (string $f): bool => $this->features[$f] ?? false);
        $features->method('getUnavailableResponse')->willReturnCallback(fn (string $f): array => [
            'available' => false, 'feature' => $f, 'reason' => 'not installed', 'hint' => 'Install it',
        ]);
        return new ProviderStatus($features, $this->gateway);
    }
}
