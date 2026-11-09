<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\ViewModel\EntityPickerValue;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentLocator;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentScanner;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityPickerReference;
use Magebit\McpHyvaCommerceTools\Model\Content\UsageFinder;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * T-181: a form is in use where a Hyvä form widget, a Magento widget directive or a widget instance names its
 * identifier. The widget's form_identifier is an entity picker: a Liveview editor object, a list of them or a legacy
 * string.
 */
class UsageFinderFormTest extends TestCase
{
    use OptionalServicesStub;

    public function testEveryKindOfFormWidgetNamingTheIdentifierIsFound(): void
    {
        $nodes = [
            ['id' => 1, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => 'contact']],
            ['id' => 2, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => 'contact_b2b']],
            ['id' => 3, 'node' => ['component' => 'widget', 'widget' => [
                'directive' => '{{widget type="Hyva\\FormBuilder\\Block\\Widget\\Form" form_identifier="contact"}}',
            ]]],
            ['id' => 4, 'node' => ['component' => 'widget', 'widget' => ['form_identifier' => 'contact']]],
            ['id' => 5, 'node' => ['component' => 'cms_block', 'block_identifier' => 'contact']],
            ['id' => 6, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => [
                'source' => 'form', 'id' => 7, 'identifier' => 'contact', 'label' => 'Contact us',
            ]]],
            ['id' => 7, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => [
                ['source' => 'form', 'id' => 8, 'identifier' => 'newsletter', 'label' => 'Newsletter'],
                ['source' => 'form', 'id' => 7, 'identifier' => 'contact', 'label' => 'Contact us'],
            ]]],
            ['id' => 8, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => [
                'source' => 'form', 'id' => 8, 'identifier' => 'newsletter', 'label' => 'Newsletter',
            ]]],
            ['id' => 9, 'node' => ['component' => 'hyva_form_widget', 'form_identifier' => [
                'source' => 'cms_page', 'id' => 2, 'identifier' => 'contact', 'label' => 'Contact page',
            ]]],
        ];
        $scanner = $this->createMock(ContentScanner::class);
        $scanner->expects($this->once())->method('findNodes')->willReturnCallback(
            function (callable $predicate, array $types, bool $ignoreMenuAcl) use ($nodes): array {
                $this->assertSame(ContentLocator::TYPES, $types);
                $this->assertTrue($ignoreMenuAcl, 'in-use decisions scan every menu');
                $matches = [];
                foreach ($nodes as $entry) {
                    if ($predicate($entry['node'])) {
                        $matches[] = ['entity_type' => 'cms_page', 'id' => $entry['id']];
                    }
                }
                return $matches;
            }
        );
        $scanner->expects($this->once())->method('findInMagentoContent')->with('form_identifier="contact"')
            ->willReturn([['entity_type' => 'cms_block', 'id' => 9, 'identifier' => 'footer', 'title' => 'Footer']]);

        $report = (new UsageFinder($scanner, $this->resource([
            ['instance_id' => 21, 'title' => 'Contact sidebar', 'theme_id' => 5, 'store_ids' => '1',
                'widget_parameters' => '{"form_identifier":"contact"}'],
            ['instance_id' => 22, 'title' => 'Other form', 'theme_id' => 5, 'store_ids' => '0',
                'widget_parameters' => '{"form_identifier":"newsletter"}'],
        ]), $this->entityPicker()))->findFormReferences('contact');

        $this->assertSame([1, 3, 4, 6, 7], array_column($report['hyva_content'], 'id'));
        $this->assertSame([9], array_column($report['magento_content'], 'id'));
        $this->assertSame(
            [['instance_id' => 21, 'title' => 'Contact sidebar', 'theme_id' => 5, 'store_ids' => '1']],
            $report['widget_instances']
        );
        $this->assertSame(7, $report['total']);
    }

    /**
     * Resolver backed by Hyvä's own EntityPickerValue.
     *
     * @return EntityPickerReference
     */
    private function entityPicker(): EntityPickerReference
    {
        return new EntityPickerReference(
            $this->optionalServices([EntityPickerValue::class => new EntityPickerValue()])
        );
    }

    /**
     * Resource connection whose widget_instance query for the form widget returns the rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return ResourceConnection
     */
    private function resource(array $rows): ResourceConnection
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects($this->once())->method('where')
            ->with('instance_type = ?', UsageFinder::FORM_WIDGET_CLASS)->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }
}
