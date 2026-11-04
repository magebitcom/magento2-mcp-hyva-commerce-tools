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
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\PreviewService;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Security\StoreScopeStub;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterGet;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Newsletter\NewsletterPublish;
use Magebit\McpHyvaCommerceTools\Tool\HyvaEmail\Template\TemplateGet;
use Magebit\Mcp\Api\ToolResultInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * T-82: messages about the template type use an explicit plural label.
 */
class TemplateLabelTest extends TestCase
{
    use StoreScopeStub;

    /**
     * @return array<string, array{0: class-string<TemplateGet|NewsletterGet>, 1: string}>
     */
    public static function getToolProvider(): array
    {
        return [
            'email template' => [TemplateGet::class, 'Several email templates are named "welcome"; pass id.'],
            'newsletter template' => [
                NewsletterGet::class,
                'Several newsletter templates are named "welcome"; pass id.',
            ],
        ];
    }

    /**
     * @dataProvider getToolProvider
     * @param class-string<TemplateGet|NewsletterGet> $class
     * @param string $expected
     */
    public function testAnAmbiguousCodeNamesTheTypeInThePlural(string $class, string $expected): void
    {
        $row = ['id' => 4, 'code' => 'welcome'];
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->method('getList')->willReturn(['total' => 2, 'items' => [$row, ['id' => 5] + $row]]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expected);

        (new $class(
            $this->features(),
            $gateway,
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope(null)
        ))->execute(['code' => 'welcome']);
    }

    /**
     * @return array<string, array{0: class-string<TemplateGet|NewsletterGet>, 1: array<string, mixed>, 2: string}>
     */
    public static function unknownTemplateProvider(): array
    {
        return [
            'email template id' => [TemplateGet::class, ['id' => 3], 'Email template #3 does not exist.'],
            'email template code' => [TemplateGet::class, ['code' => 'gone'], 'Email template "gone" does not exist.'],
            'newsletter id' => [NewsletterGet::class, ['id' => 3], 'Newsletter template #3 does not exist.'],
            'newsletter code' => [
                NewsletterGet::class,
                ['code' => 'gone'],
                'Newsletter template "gone" does not exist.',
            ],
        ];
    }

    /**
     * T-128: an unknown id or code answers like the page and menu tools: the same tool error, capitalised label.
     *
     * @dataProvider unknownTemplateProvider
     * @param class-string<TemplateGet|NewsletterGet> $class
     * @param array<string, mixed> $arguments
     * @param string $expected
     */
    public function testAnUnknownTemplateAnswersLikeThePageAndMenuTools(
        string $class,
        array $arguments,
        string $expected
    ): void {
        $gateway = $this->createMock(EmailGatewayInterface::class);
        $gateway->method('get')->willReturn(null);
        $gateway->method('getList')->willReturn(['total' => 0, 'items' => []]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expected);

        (new $class(
            $this->features(),
            $gateway,
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope(null)
        ))->execute($arguments);
    }

    public function testTheScopeRefusalNamesNewsletterTemplatesInThePlural(): void
    {
        $result = (new NewsletterPublish(
            $this->features(),
            $this->createMock(EmailGatewayInterface::class),
            $this->createMock(ContentService::class),
            $this->createMock(PreviewService::class),
            $this->storeScope([1])
        ))->execute(['id' => 4, 'confirm' => true]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString(
            'may not change newsletter templates: they apply to every store view.',
            $this->text($result)
        );
    }

    /**
     * Text of a tool result.
     *
     * @param ToolResultInterface $result
     * @return string
     */
    private function text(ToolResultInterface $result): string
    {
        return implode("\n", array_map(
            static fn (array $block): string => is_string($block['text'] ?? null) ? $block['text'] : '',
            $result->getContent()
        ));
    }

    /**
     * Feature checker with Email Templates available.
     *
     * @return FeatureCheckerInterface
     */
    private function features(): FeatureCheckerInterface
    {
        $features = $this->createMock(FeatureCheckerInterface::class);
        $features->method('isAvailable')->willReturn(true);
        return $features;
    }
}
