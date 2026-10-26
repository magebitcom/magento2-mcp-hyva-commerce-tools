<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaCms;

use Hyva\CmsLiveviewEditor\Model\Component\Provider as ComponentProvider;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Api\Adapter\TranslationGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\TranslationScanner;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Translation\TranslationRun;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TranslationRunTest extends TestCase
{
    /**
     * @var ContentService&MockObject
     */
    private ContentService $contentService;

    /**
     * @var ContentAcl&MockObject
     */
    private ContentAcl $acl;

    /**
     * @var TranslationGatewayInterface&MockObject
     */
    private TranslationGatewayInterface $gateway;

    protected function setUp(): void
    {
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('getWorkingDocument')->willReturn(['uid' => 'doc', 'version' => 1,
            'content' => [
                'h1' => ['uid' => 'h1', 'component' => 'heading', 'text' => 'Autumn sale',
                    'translations' => ['text' => [2 => 'Soldes']]],
                'h2' => ['uid' => 'h2', 'component' => 'heading', 'text' => 'Coats'],
            ]]);
        $this->acl = $this->createMock(ContentAcl::class);
        $this->gateway = $this->createMock(TranslationGatewayInterface::class);
        $this->gateway->method('settings')->willReturn([
            'enabled' => true, 'model' => 'm', 'default_language' => 'en_US', 'stores' => [
                ['store_id' => 1, 'code' => 'en', 'store_locale' => 'en_US', 'target_locale' => 'en_US'],
                ['store_id' => 2, 'code' => 'fr', 'store_locale' => 'fr_FR', 'target_locale' => 'fr_FR'],
            ],
        ]);
    }

    public function testMissingTextsAreTranslatedAndWrittenToTheDraft(): void
    {
        $this->acl->method('isAllowed')->willReturn(true);
        $this->gateway->expects($this->once())->method('translate')
            ->with($this->callback(static fn (array $items): bool => count($items) === 1
                && $items[0]['originalText'] === 'Coats'
                && $items[0]['targetStores'][0]['translationKey'] === 'h2_text_2'))
            ->willReturn(['h2_text_2' => 'Manteaux']);
        $this->contentService->expects($this->once())->method('saveDraft')
            ->with('cms_page', 7, $this->callback(
                static fn (array $doc): bool => (
                    $doc['content']['h2']['translations']['text'][2] ?? null
                ) === 'Manteaux'
                    && ($doc['content']['h1']['translations']['text'][2] ?? null) === 'Soldes'
            ), 'AI translations', null);
        $payload = $this->payload($this->tool()->execute([
            'entity_type' => 'cms_page', 'id' => 7, 'write_draft' => true,
        ]));
        $this->assertSame(1, $payload['translated']);
        $this->assertTrue($payload['draft_saved']);
    }

    public function testTextsAreBatchedAndUnsafeOnesSkipped(): void
    {
        $content = [
            'p' => ['uid' => 'p', 'component' => 'heading', 'text' => "line one\nline two"],
            'h' => ['uid' => 'h', 'component' => 'heading', 'text' => "<p>Coats</p>\n<p>Hats</p>"],
            'x' => ['uid' => 'x', 'component' => 'heading', 'text' => str_repeat('a', 2001)],
        ];
        for ($i = 0; $i < 20; $i++) {
            $content['t' . $i] = ['uid' => 't' . $i, 'component' => 'heading', 'text' => 'Text ' . $i];
        }
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('getWorkingDocument')->willReturn(['uid' => 'doc', 'content' => $content]);
        $sent = [];
        $this->gateway->expects($this->exactly(2))->method('translate')->willReturnCallback(
            static function (array $items) use (&$sent): array {
                $sent[] = array_column($items, 'originalText');
                return [];
            }
        );
        $payload = $this->payload($this->tool()->execute(['entity_type' => 'cms_page', 'id' => 7, 'max_texts' => 100]));
        $this->assertSame([15, 6], array_map('count', $sent), 'at most 15 texts per AI request');
        $this->assertSame('<p>Coats</p> <p>Hats</p>', $sent[0][0], 'line breaks in HTML are collapsed');
        $this->assertIsArray($payload['skipped']);
        $this->assertSame(['p', 'x'], array_column($payload['skipped'], 'uid'));
    }

    public function testTheDraftIsSavedAgainstTheVersionReadBeforeTheAiCall(): void
    {
        $this->acl->method('isAllowed')->willReturn(true);
        $this->contentService = $this->createMock(ContentService::class);
        $this->contentService->method('getWorkingDocument')->willReturn(['uid' => 'doc', 'version' => ['uid' => 'v1'],
            'content' => ['h2' => ['uid' => 'h2', 'component' => 'heading', 'text' => 'Coats']]]);
        $this->gateway->method('translate')->willReturn(['h2_text_2' => 'Manteaux']);
        $bases = [];
        $this->contentService->expects($this->exactly(2))->method('saveDraft')->willReturnCallback(
            static function (string $type, int $id, array $doc, ?string $name, ?string $base) use (&$bases): array {
                $bases[] = $base;
                return [];
            }
        );
        $this->tool()->execute(['entity_type' => 'cms_page', 'id' => 7, 'write_draft' => true]);
        $this->tool()->execute(['entity_type' => 'cms_page', 'id' => 7, 'write_draft' => true,
            'base_version_uid' => 'mine']);
        $this->assertSame(['v1', 'mine'], $bases);
    }

    public function testTheTranslateResourceIsOnlyCheckedWhileTheModuleIsOn(): void
    {
        $this->assertContains('Hyva_CmsAiTranslations::translate', $this->tool(true)->getContextualAclResources([]));
        $this->assertNotContains('Hyva_CmsAiTranslations::translate', $this->tool(false)->getContextualAclResources([]));
    }

    public function testWriteDraftNeedsSavePermission(): void
    {
        $this->acl->method('isAllowed')->willReturn(false);
        $this->gateway->expects($this->never())->method('translate');
        $this->contentService->expects($this->never())->method('saveDraft');
        $payload = $this->payload($this->tool()->execute([
            'entity_type' => 'cms_page', 'id' => 7, 'write_draft' => true,
        ]));
        $this->assertSame(403, $payload['status']);
    }

    /**
     * @return TranslationRun
     */
    private function tool(bool $moduleEnabled = true): TranslationRun
    {
        $locator = $this->createMock(ContentLocator::class);
        $locator->method('locate')->willReturn(['type' => 'cms_page', 'id' => 7, 'identifier' => 'x', 'title' => 'X',
            'is_active' => true, 'store_ids' => [0]]);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $features->method('getStatus')->willReturn(['enabled' => $moduleEnabled]);
        $provider = $this->createMock(ComponentProvider::class);
        $provider->method('getComponent')->willReturn(
            ['content' => ['text' => ['type' => 'text', 'translate' => true]]]
        );
        return new TranslationRun(
            $locator,
            $this->contentService,
            $this->acl,
            $features,
            $this->gateway,
            new TranslationScanner(new ComponentTree($provider))
        );
    }

    /**
     * @param ToolResultInterface $result
     * @return array<string, mixed>
     */
    private function payload(ToolResultInterface $result): array
    {
        $text = $result->getContent()[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload, $text);
        return $payload;
    }
}
