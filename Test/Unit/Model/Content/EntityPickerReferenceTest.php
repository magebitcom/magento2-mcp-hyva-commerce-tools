<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Model\Content;

use Hyva\CmsLiveviewEditor\ViewModel\EntityPickerValue;
use Magebit\McpHyvaCommerceTools\Model\Content\EntityPickerReference;
use Magebit\McpHyvaCommerceTools\Test\Unit\Model\Adapter\OptionalServicesStub;
use PHPUnit\Framework\TestCase;

/**
 * Entity picker values (Liveview editor object, list of objects, legacy string) resolve with Hyvä's own
 * EntityPickerValue::resolveIdentifier(), entry by entry.
 */
class EntityPickerReferenceTest extends TestCase
{
    use OptionalServicesStub;

    /**
     * @return array<string, array{mixed, string, list<string>}>
     */
    public static function values(): array
    {
        return [
            'legacy string' => ['contact', 'form', ['contact']],
            'legacy numeric id' => [12, 'cms_block', ['12']],
            'editor object' => [
                ['source' => 'form', 'id' => 7, 'identifier' => 'contact', 'label' => 'Contact'], 'form', ['contact'],
            ],
            'object without identifier resolves by id' => [
                ['source' => 'cms_block', 'id' => 12, 'label' => 'Footer'], 'cms_block', ['12'],
            ],
            'object of another source' => [
                ['source' => 'cms_page', 'id' => 3, 'identifier' => 'contact'], 'form', [],
            ],
            'list of objects, every entry' => [
                [
                    ['source' => 'cms_block', 'id' => 4, 'identifier' => 'first'],
                    ['source' => 'cms_block', 'id' => 5, 'identifier' => 'second'],
                ],
                'cms_block',
                ['first', 'second'],
            ],
            'empty' => [null, 'form', []],
            'empty list' => [[], 'form', []],
        ];
    }

    /**
     * @param mixed $value
     * @param string $source
     * @param list<string> $expected
     * @return void
     * @dataProvider values
     */
    public function testResolvesEveryEntryTheWayHyvaRendersIt(mixed $value, string $source, array $expected): void
    {
        $reference = new EntityPickerReference($this->optionalServices(
            [EntityPickerValue::class => new EntityPickerValue()]
        ));

        $this->assertSame($expected, $reference->resolveAll($value, $source));
    }

    public function testReferencesMatchesAnyResolvedEntry(): void
    {
        $reference = new EntityPickerReference($this->optionalServices(
            [EntityPickerValue::class => new EntityPickerValue()]
        ));
        $list = [['source' => 'cms_block', 'id' => 4, 'identifier' => 'first'], ['source' => 'cms_block', 'id' => 5]];

        $this->assertTrue($reference->references($list, 'cms_block', ['5', 'other']));
        $this->assertFalse($reference->references($list, 'cms_block', ['4']));
        $this->assertFalse($reference->references($list, 'form', ['first']));
    }
}
