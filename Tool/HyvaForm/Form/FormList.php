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
    private const SCAN_PAGE_SIZE = 200;

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
        $search = $this->stringArg($arguments, 'search');
        $storeId = $this->intArg($arguments, 'store_id');
        $page = $this->intArg($arguments, 'page', 1) ?? 1;
        $pageSize = $this->intArg($arguments, 'page_size', 50) ?? 50;
        if ($this->storeScopeGuard->restrictedStoreIds() === null) {
            $result = $this->formGateway->getList($search, $storeId, $page, $pageSize);
            return $this->jsonResult($result, ['total' => $result['total']]);
        }

        // A restricted role sees only forms of its store views: filter every form, then paginate, so the total is
        // right. Form counts are admin-scale.
        $visible = [];
        $batch = 1;
        do {
            $chunk = $this->formGateway->getList($search, $storeId, $batch, self::SCAN_PAGE_SIZE);
            foreach ($chunk['items'] as $form) {
                if ($this->storeScopeGuard->allowsRead($form['store_ids'])) {
                    $visible[] = $form;
                }
            }
            $batch++;
        } while ($chunk['items'] !== [] && ($batch - 1) * self::SCAN_PAGE_SIZE < $chunk['total']);
        $pageSize = max(1, min(200, $pageSize));
        $result = [
            'total' => count($visible),
            'items' => array_slice($visible, (max(1, $page) - 1) * $pageSize, $pageSize),
        ];
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
