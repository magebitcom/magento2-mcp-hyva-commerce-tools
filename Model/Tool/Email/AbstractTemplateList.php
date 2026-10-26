<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * List email or newsletter templates with their Hyvä CMS state.
 */
abstract class AbstractTemplateList extends AbstractEmailTool
{
    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'List %ss (newest first): name, subject, whether Hyvä CMS designs them (hyva_enabled, draft, '
            . 'published, preheader)%s.',
            static::LABEL,
            static::TEMPLATE_TYPE === 'email_template' ? ' and which store emails send them (assigned_to)' : ''
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->string('search', fn (StringBuilder $s) => $s->description('Name or subject contains.'))
            ->integer('page', fn (IntegerBuilder $i) => $i->minimum(1))
            ->integer('page_size', fn (IntegerBuilder $i) => $i->minimum(1)->maximum(200))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $result = $this->emailGateway->getList(
            static::TEMPLATE_TYPE,
            $this->stringArg($arguments, 'search'),
            $this->intArg($arguments, 'page', 1) ?? 1,
            $this->intArg($arguments, 'page_size', 50) ?? 50
        );
        return $this->jsonResult($result, ['total' => $result['total']]);
    }
}
