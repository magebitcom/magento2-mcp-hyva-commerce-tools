<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Test\Unit\Exception;

use Magebit\McpHyvaCommerceTools\Exception\AclRefusalException;
use Magebit\McpHyvaCommerceTools\Exception\OutOfScopeException;
use PHPUnit\Framework\TestCase;

class AclRefusalExceptionTest extends TestCase
{
    public function testOneMissingResourceIsNamedAsResource(): void
    {
        $this->assertSame(['resource' => 'A::a'], (new AclRefusalException(__('No.'), 'A::a'))->getData());
        $this->assertSame(['resource' => 'A::a'], (new AclRefusalException(__('No.'), ['A::a']))->getData());
    }

    public function testSeveralMissingResourcesAreListed(): void
    {
        $this->assertSame(
            ['resources' => ['A::a', 'B::b']],
            (new AclRefusalException(__('No.'), ['A::a', 'B::b']))->getData()
        );
    }

    public function testAScopeRefusalCarriesItsExtraData(): void
    {
        $this->assertSame([], (new OutOfScopeException(__('No.')))->getData());
        $this->assertSame(
            ['denied_store_ids' => [2]],
            (new OutOfScopeException(__('No.'), ['denied_store_ids' => [2]]))->getData()
        );
    }
}
