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
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\SchemaContribution;

/**
 * Input properties that select one Hyvä CMS entity: entity_type (optional) + id | identifier [+ store_id].
 */
class EntityTarget implements SchemaContribution
{
    /**
     * @param array $types Allowed entity types; empty means the tool fixes the type itself.
     * @param bool $requireType
     * @phpstan-param list<string> $types
     */
    private function __construct(
        private readonly array $types,
        private readonly bool $requireType
    ) {
    }

    /**
     * Entity type is chosen by the caller.
     *
     * @param array $types
     * @param bool $required
     * @return self
     * @phpstan-param list<string> $types
     */
    public static function ofTypes(array $types, bool $required = true): self
    {
        return new self($types, $required);
    }

    /**
     * Entity type is implied by the tool (e.g. hyva_cms.page.get).
     *
     * @return self
     */
    public static function fixed(): self
    {
        return new self([], false);
    }

    /**
     * @inheritDoc
     */
    public function applyTo(ObjectBuilder $object): void
    {
        if ($this->types !== []) {
            $object->string('entity_type', function (StringBuilder $s): void {
                $s->enum($this->types)->description('Content type: ' . implode(', ', $this->types) . '.');
                if ($this->requireType) {
                    $s->required();
                }
            });
        }
        $object
            ->integer('id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Entity id (cms_page.page_id, cms_block.block_id or menu id).'))
            ->string('identifier', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Identifier / URL key. Used when id is not given.'))
            ->integer('store_id', fn (IntegerBuilder $i) => $i
                ->minimum(0)
                ->description('Store view used to disambiguate an identifier.'));
    }
}
