<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool;

use Hyva\CmsConnect\Exception\ConflictException;
use Hyva\CmsConnect\Exception\UnprocessableContentException;
use Hyva\CmsLiveviewEditor\Model\Security\ContentTypeAuthorization;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Acl\ContentAcl;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractContentTool;
use Magebit\McpHyvaCommerceTools\Tool\HyvaCms\Component\ComponentValidate;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageReplace;
use Magebit\McpHyvaCommerceTools\Tool\HyvaMedia\Image\ImageUpdateAlt;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\SecurityViolationException;
use PHPUnit\Framework\TestCase;

class AbstractContentToolTest extends TestCase
{
    /**
     * @var ContentAcl
     */
    private ContentAcl $acl;

    protected function setUp(): void
    {
        $authorization = $this->createMock(ContentTypeAuthorization::class);
        $authorization->method('getResource')->willReturnCallback(
            static fn (string $type, string $verb): string => 'Hyva::' . $type . '_' . $verb
        );
        $this->acl = new ContentAcl($authorization);
    }

    public function testResourcesFollowTheEntityTypeArgument(): void
    {
        $tool = $this->tool(null);
        $this->assertSame(
            ['Hyva::cms_page_save', 'Hyva::cms_page_version_history', 'Hyva_Cms::transfer'],
            $tool->getContextualAclResources(['entity_type' => 'cms_page'])
        );
        $this->assertSame(
            ['Hyva::menu_save', 'Hyva::menu_version_history', 'Hyva_Cms::transfer'],
            $tool->getContextualAclResources(['entity_type' => 'menu'])
        );
    }

    public function testUnknownOrMissingTypeOnlyKeepsTheExtraResources(): void
    {
        $tool = $this->tool(null);
        $this->assertSame(['Hyva_Cms::transfer'], $tool->getContextualAclResources([]));
        $this->assertSame(['Hyva_Cms::transfer'], $tool->getContextualAclResources(['entity_type' => 'product']));
        $this->assertSame(['Hyva_Cms::transfer'], $tool->getContextualAclResources(['entity_type' => ['cms_page']]));
    }

    /**
     * @dataProvider refusalProvider
     * @param \Throwable $exception
     * @param int $status
     */
    public function testHyvaRefusalsBecomeSoftErrorsWithStatus(\Throwable $exception, int $status): void
    {
        $result = $this->tool($exception)->execute(['entity_type' => 'cms_page']);
        $this->assertTrue($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $payload = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($payload);
        $this->assertSame($status, $payload['status'] ?? null);
        if ($exception instanceof UnprocessableContentException) {
            $this->assertSame(['uid1' => ['label' => 'Required']], $payload['field_errors'] ?? null);
        }
    }

    /**
     * @return array<string, array{0: \Throwable, 1: int}>
     */
    public static function refusalProvider(): array
    {
        return [
            'provider refused' => [
                new UnprocessableContentException(__('Refused'), ['uid1' => ['label' => 'Required']]),
                422,
            ],
            'stale draft' => [new ConflictException('Saved elsewhere'), 409],
            'no permission' => [new AuthorizationException(__('Not allowed')), 403],
            'content guard' => [new SecurityViolationException(__('Too large')), 422],
        ];
    }

    /**
     * Content tool needing save + version history on the type, plus Transfer Center; run() throws $exception.
     *
     * @param \Throwable|null $exception
     * @return AbstractContentTool
     */
    private function tool(?\Throwable $exception): AbstractContentTool
    {
        return new class ($this->acl, $exception) extends AbstractContentTool {
            public const CONTENT_VERBS = [ContentAcl::VERB_SAVE, ContentAcl::VERB_VERSION_HISTORY];
            public const EXTRA_ACL_RESOURCES = [ContentAcl::TRANSFER_RESOURCE];

            /**
             * @param ContentAcl $acl
             * @param \Throwable|null $exception
             */
            public function __construct(private readonly ContentAcl $acl, private readonly ?\Throwable $exception)
            {
            }

            /**
             * @inheritDoc
             */
            public function getTitle(): string
            {
                return 'Test';
            }

            /**
             * @inheritDoc
             */
            public function getDescription(): string
            {
                return 'Test';
            }

            /**
             * @inheritDoc
             */
            public function getInputSchema(): array
            {
                return [];
            }

            /**
             * @inheritDoc
             */
            protected function contentAcl(): ContentAcl
            {
                return $this->acl;
            }

            /**
             * @inheritDoc
             */
            protected function run(array $arguments): ToolResultInterface
            {
                if ($this->exception !== null) {
                    throw $this->exception;
                }
                return $this->jsonResult(['ok' => true]);
            }
        };
    }

    /**
     * Regression: component.validate with only id/identifier reads a page, so it needs page view.
     */
    public function testComponentValidateChecksPageViewWithoutEntityType(): void
    {
        $tool = $this->withAcl(ComponentValidate::class);
        $this->assertSame(['Hyva::cms_page_view'], $tool->getContextualAclResources(['id' => 5]));
        $this->assertSame(['Hyva::cms_page_view'], $tool->getContextualAclResources(['identifier' => 'home']));
        $this->assertSame(['Hyva::cms_block_view'], $tool->getContextualAclResources([
            'entity_type' => 'cms_block',
            'id' => 5,
        ]));
        $this->assertSame([], $tool->getContextualAclResources(['components' => []]));
    }

    public function testImageWriteToolsNeedSaveOnTheNamedScope(): void
    {
        foreach ([ImageReplace::class, ImageUpdateAlt::class] as $class) {
            $tool = $this->withAcl($class);
            $this->assertSame(['Hyva::cms_block_save'], $tool->getContextualAclResources(['scope' => 'cms_block']));
            $this->assertSame([], $tool->getContextualAclResources(['scope' => 'global']));
            $this->assertSame([], $tool->getContextualAclResources(['scope' => 'all']));
            $this->assertSame([], $tool->getContextualAclResources(['scope' => 'product']));
        }
    }

    /**
     * Real tool class with only its contentAcl dependency set.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function withAcl(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $tool = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('contentAcl')->setValue($tool, $this->acl);
        return $tool;
    }
}
