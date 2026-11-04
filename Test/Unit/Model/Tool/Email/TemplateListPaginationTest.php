<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Tool\Email;

use Magebit\McpHyvaCommerceTools\Api\Adapter\EmailGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Model\Tool\Email\AbstractTemplateList;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterList;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplateList;
use PHPUnit\Framework\TestCase;

/**
 * Email and newsletter lists echo the page they answer, like the CMS entity lists.
 */
class TemplateListPaginationTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @return array<string, array{class-string<AbstractTemplateList>}>
     */
    public static function listTools(): array
    {
        return ['email templates' => [TemplateList::class], 'newsletters' => [NewsletterList::class]];
    }

    /**
     * @dataProvider listTools
     * @param class-string<AbstractTemplateList> $class
     */
    public function testListEchoesPageAndPageSize(string $class): void
    {
        $reply = $this->reply($class, ['page' => 2, 'page_size' => 5], 2, 5);

        $this->assertSame(['total', 'page', 'page_size', 'items'], array_keys($reply));
        $this->assertSame(2, $reply['page']);
        $this->assertSame(5, $reply['page_size']);
        $this->assertSame(7, $reply['total']);
    }

    /**
     * @dataProvider listTools
     * @param class-string<AbstractTemplateList> $class
     */
    public function testDefaultsAreEchoedWhenOmitted(string $class): void
    {
        $reply = $this->reply($class, [], 1, 50);

        $this->assertSame(1, $reply['page']);
        $this->assertSame(50, $reply['page_size']);
    }

    /**
     * @dataProvider listTools
     * @param class-string<AbstractTemplateList> $class
     */
    public function testOutOfRangeValuesAreClampedAndEchoed(string $class): void
    {
        $reply = $this->reply($class, ['page' => 0, 'page_size' => 500], 1, 200);

        $this->assertSame(1, $reply['page']);
        $this->assertSame(200, $reply['page_size']);
    }

    /**
     * Run the list and decode its reply, asserting the gateway got the expected page.
     *
     * @param class-string<AbstractTemplateList> $class
     * @param array<string, mixed> $arguments
     * @param int $page
     * @param int $pageSize
     * @return array<string, mixed>
     */
    private function reply(string $class, array $arguments, int $page, int $pageSize): array
    {
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->expects($this->once())->method('getList')
            ->with($class::TEMPLATE_TYPE, null, $page, $pageSize)
            ->willReturn(['total' => 7, 'items' => [['id' => 1]]]);
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        $tool = new $class(
            $features,
            $gateway,
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope(null)
        );

        $result = $tool->execute($arguments);

        $this->assertFalse($result->isError());
        $text = $result->getContent()[0]['text'] ?? '';
        $decoded = json_decode(is_string($text) ? $text : '', true);
        $this->assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
