<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Tool\HyvaEmail;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ComponentTree;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DraftEditor;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterCreateDraft;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A created newsletter always answers with its draft version, like page, menu and form creates.
 */
class NewsletterCreateDraftTest extends TestCase
{
    use StoreScopeStub;

    private const ARGS = ['code' => 'Autumn', 'sender_name' => 'Shop', 'sender_email' => 'shop@example.com'];
    private const SAVED = ['version_id' => 3, 'version_uid' => 'v-uid', 'warnings' => []];

    /**
     * Content service mock.
     *
     * @var ContentService&MockObject
     */
    private MockObject $content;

    /**
     * Component tree mock.
     *
     * @var ComponentTree&MockObject
     */
    private MockObject $tree;

    /**
     * Draft editor mock.
     *
     * @var DraftEditor&MockObject
     */
    private MockObject $editor;

    protected function setUp(): void
    {
        $this->content = $this->createMock(ContentService::class);
        $this->tree = $this->createMock(ComponentTree::class);
        $this->editor = $this->createMock(DraftEditor::class);
        $this->content->method('getWorkingDocument')->willReturn(['root' => [['uid' => 'wrapper']]]);
        $this->tree->method('getRootNodes')->willReturn([['uid' => 'wrapper']]);
    }

    public function testCreateDraftReturnsDraftVersion(): void
    {
        $this->editor->method('buildNodes')->willReturn([]);
        $this->tree->expects($this->never())->method('insertNode');
        $this->content->expects($this->once())->method('saveDraft')
            ->with(EmailGatewayInterface::TYPE_NEWSLETTER, 12, ['root' => [['uid' => 'wrapper']]], 'Created by MCP')
            ->willReturn(self::SAVED);

        $reply = $this->create(self::ARGS);

        $this->assertSame(12, $reply['id']);
        $this->assertSame(self::SAVED, $reply['draft']);
    }

    public function testComponentsAreInsertedIntoTheWrapperBeforeSaving(): void
    {
        $node = ['uid' => 'n1', 'component' => 'email_text'];
        $this->editor->method('buildNodes')->willReturn([$node]);
        $this->tree->method('newDocument')->willReturn([]);
        $this->tree->method('createNode')->willReturn([]);
        $this->content->method('validate')->willReturn(['valid' => true, 'issues' => []]);
        $this->tree->expects($this->exactly(2))->method('insertNode')
            ->willReturnCallback(static fn (array $doc, array $n, ?string $parent): array
                => $parent === 'wrapper' ? ['root' => [['uid' => 'wrapper', 'children' => [$n]]]] : $doc);
        $this->content->expects($this->once())->method('saveDraft')
            ->with(
                EmailGatewayInterface::TYPE_NEWSLETTER,
                12,
                ['root' => [['uid' => 'wrapper', 'children' => [$node]]]],
                'Created by MCP'
            )
            ->willReturn(self::SAVED);

        $reply = $this->create(self::ARGS + ['components' => [['component' => 'email_text']]]);

        $this->assertSame(self::SAVED, $reply['draft']);
    }

    /**
     * Run the create and decode its reply.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function create(array $arguments): array
    {
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->expects($this->once())->method('createNewsletter')->willReturn(12);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $tool = new NewsletterCreateDraft(
            $features,
            $gateway,
            $this->content,
            $this->createMock(PreviewService::class),
            $this->storeScope(null),
            $this->tree,
            $this->editor
        );

        $result = $tool->execute($arguments);

        $this->assertFalse($result->isError(), json_encode($result->getContent()) ?: '');
        $text = $result->getContent()[0]['text'] ?? '';
        $decoded = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
