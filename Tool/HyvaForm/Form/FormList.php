<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form;

use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.form.list`.
 */
class FormList extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.form.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_list';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Forms';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'List Hyvä Form Builder forms (newest first) with identifier, title, enabled flag, store views, whether '
            . 'they '
            . 'have a draft and a published version, and where submissions go (recipients, webhook).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('search', fn (StringBuilder $s) => $s->description('Title contains.'))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $result = $this->formGateway->getList(
            $this->stringArg($arguments, 'search'),
            $this->intArg($arguments, 'store_id'),
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
