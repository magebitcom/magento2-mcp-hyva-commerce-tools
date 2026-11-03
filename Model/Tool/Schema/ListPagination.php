<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Schema;

use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * The `page` / `page_size` arguments every list tool takes, with the default and the cap spelled out.
 * There is no `limit` or `offset` argument.
 */
class ListPagination implements SchemaContribution
{
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 200;

    /** Sentence for tool descriptions that names the real pagination arguments. */
    public const DESCRIPTION_NOTE = 'Paginate with page (1-based) and page_size (default 50, max 200); '
        . 'there is no limit or offset argument.';

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        $object
            ->integer('page', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('1-based page number (default 1).'))
            ->integer('page_size', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->maximum(self::MAX_PAGE_SIZE)
                ->description(sprintf(
                    'Rows per page (default %d, max %d). Use this instead of limit.',
                    self::DEFAULT_PAGE_SIZE,
                    self::MAX_PAGE_SIZE
                )));
    }
}
