<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityWriter;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterfaceFactory;
use Magento\Cms\Api\Data\PageInterfaceFactory;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class EntityWriterTest extends TestCase
{
    /**
     * @var Page|null
     */
    private ?Page $saved = null;

    /**
     * @dataProvider defaultLayoutProvider
     * @param array<string, mixed> $fields
     * @param mixed $configValue
     * @param string $expected
     */
    public function testNewPageAlwaysGetsALayout(array $fields, mixed $configValue, string $expected): void
    {
        $this->writer($configValue)->create(ContentLocator::TYPE_PAGE, $fields + ['title' => 'T', 'identifier' => 't']);

        $saved = $this->saved;
        $this->assertNotNull($saved);
        $this->assertSame($expected, $saved->getData('page_layout'));
        $this->assertSame($fields['content_heading'] ?? '', $saved->getData('content_heading'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: string}>
     */
    public static function defaultLayoutProvider(): array
    {
        return [
            'omitted: store default' => [[], 'cms-full-width', 'cms-full-width'],
            'null: store default' => [['page_layout' => null], '2columns-left', '2columns-left'],
            'blank: store default' => [['page_layout' => '  '], 'cms-full-width', 'cms-full-width'],
            'no config: Magento default' => [[], null, '1column'],
            'explicit layout kept' => [['page_layout' => 'empty'], 'cms-full-width', 'empty'],
            'explicit heading kept' => [['content_heading' => 'Deals'], 'cms-full-width', 'cms-full-width'],
        ];
    }

    public function testUpdateRejectsAnEmptyLayout(): void
    {
        $page = $this->page();
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getById')->willReturn($page);
        $repository->expects($this->never())->method('save');
        $writer = new EntityWriter(
            $this->createMock(PageInterfaceFactory::class),
            $repository,
            $this->createMock(BlockInterfaceFactory::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(AppState::class),
            $this->createMock(ScopeConfigInterface::class)
        );

        $this->expectException(LocalizedException::class);
        $writer->update(ContentLocator::TYPE_PAGE, 5, ['page_layout' => '']);
    }

    /**
     * Writer whose page repository records the saved page.
     *
     * @param mixed $configValue
     * @return EntityWriter
     */
    private function writer(mixed $configValue): EntityWriter
    {
        $factory = $this->createMock(PageInterfaceFactory::class);
        $factory->method('create')->willReturn($this->page());
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function (Page $page): Page {
            $this->saved = $page;
            return $page;
        });
        $appState = $this->createMock(AppState::class);
        $appState->method('emulateAreaCode')->willReturnCallback(static fn (string $area, callable $fn) => $fn());
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->with(EntityWriter::XML_PATH_DEFAULT_PAGE_LAYOUT)->willReturn($configValue);

        return new EntityWriter(
            $factory,
            $repository,
            $this->createMock(BlockInterfaceFactory::class),
            $this->createMock(BlockRepositoryInterface::class),
            $appState,
            $config
        );
    }

    /**
     * Page.
     *
     * @return Page
     */
    private function page(): Page
    {
        $page = $this->getMockBuilder(Page::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $page->method('getId')->willReturn(20);
        return $page;
    }
}
