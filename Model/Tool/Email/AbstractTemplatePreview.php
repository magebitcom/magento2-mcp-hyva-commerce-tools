<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\Email;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * Preview links and an optional server-side render with Hyvä's sample data.
 */
abstract class AbstractTemplatePreview extends AbstractEmailTool
{
    private const MAX_HTML = 200000;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Preview a %s: editor preview links per store view (no admin login needed, valid up to 7 days) and, '
            . 'with render=true, the HTML rendered with Hyvä\'s sample customer and order (edition draft or '
            . 'published).',
            static::LABEL
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withTarget(Schema::object())
            ->boolean('render', fn (BooleanBuilder $b) => $b->description('Also return rendered HTML.'))
            ->string('edition', fn (StringBuilder $s) => $s->enum(['draft', 'published']))
            ->integer('store_id', fn (IntegerBuilder $i) => $i->minimum(0))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $template = $this->locateTemplate($arguments);
        $result = [
            'id' => $template['id'],
            'preview' => $this->previewService->getPreview(
                static::TEMPLATE_TYPE,
                $template['id'],
                $this->intArg($arguments, 'store_id')
            ),
        ];
        if ($this->boolArg($arguments, 'render')) {
            $edition = $this->stringArg($arguments, 'edition', 'draft') ?? 'draft';
            $html = $this->emailGateway->render(
                static::TEMPLATE_TYPE,
                $template['id'],
                $edition,
                $this->intArg($arguments, 'store_id')
            );
            $result['edition'] = $edition;
            $result['html_bytes'] = strlen($html);
            $result['html'] = strlen($html) > self::MAX_HTML ? substr($html, 0, self::MAX_HTML) : $html;
            $result['html_truncated'] = strlen($html) > self::MAX_HTML;
        }
        return $this->jsonResult($result, ['id' => $template['id']]);
    }
}
