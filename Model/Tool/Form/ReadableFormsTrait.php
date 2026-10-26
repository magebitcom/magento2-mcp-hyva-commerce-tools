<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Form;

use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;

/**
 * Every form a store-restricted caller may read, for form tools (AbstractFormTool's gateway and scope guard).
 *
 * Hyvä filters forms by one store view only, so the caller's scope is applied here, over all pages: totals then
 * count only readable forms, and none past the first page is missed. Form counts are admin-scale.
 *
 * @phpstan-import-type FormRow from FormGatewayInterface
 */
trait ReadableFormsTrait
{
    /**
     * Readable forms, newest first, and how many forms the scope hid.
     *
     * @param string|null $search
     * @param int|null $storeId
     * @return array
     * @phpstan-return array{items: list<FormRow>, hidden: int}
     */
    private function readableForms(?string $search, ?int $storeId): array
    {
        $pageSize = 200;
        $visible = [];
        $hidden = 0;
        $page = 1;
        do {
            $chunk = $this->formGateway->getList($search, $storeId, $page, $pageSize);
            foreach ($chunk['items'] as $form) {
                if ($this->storeScopeGuard->allowsRead($form['store_ids'])) {
                    $visible[] = $form;
                } else {
                    $hidden++;
                }
            }
            $page++;
        } while ($chunk['items'] !== [] && ($page - 1) * $pageSize < $chunk['total']);
        return ['items' => $visible, 'hidden' => $hidden];
    }
}
