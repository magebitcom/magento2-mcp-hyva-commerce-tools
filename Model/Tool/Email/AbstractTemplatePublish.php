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
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * Publish the Hyvä CMS draft of an email or newsletter template.
 */
abstract class AbstractTemplatePublish extends AbstractEmailTool
{
    public const IS_WRITE = true;

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return sprintf(
            'Make the %s LIVE: publish its Hyvä CMS draft (or a version_id). From then on %s. The previous version '
            . 'stays in version history.',
            static::LABEL,
            static::TEMPLATE_TYPE === 'email_template'
                ? 'every store email that sends this template (assigned_to) uses the new design'
                : 'newsletters queued from this template use it'
        );
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withTarget(Schema::object())
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeEmail(array $arguments): ToolResultInterface
    {
        $template = $this->locateTemplate($arguments);
        $published = $this->contentService->publish(
            static::TEMPLATE_TYPE,
            $template['id'],
            $this->intArg($arguments, 'version_id')
        );
        return $this->jsonResult([
            'published' => true,
            'id' => $template['id'],
            'result' => $published,
            'assigned_to' => $template['assigned_to'],
            'note' => $template['assigned_to'] === [] && static::TEMPLATE_TYPE === 'email_template'
                ? 'No store email sends this template yet (see hyva_email.audit.coverage); assign it under Stores > '
                    . 'Configuration > Sales Emails / Customer Configuration.'
                : null,
        ], ['id' => $template['id']]);
    }
}
