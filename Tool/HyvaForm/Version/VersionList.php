<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Version;

use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.version.list`.
 */
class VersionList extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.version.list';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_version_list';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_version_history';

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'List Hyvä Form Versions';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Version history of a form, newest first: version_id, name, author, time, whether it was published.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateForm($arguments);
        $versions = $this->contentService->getVersions(self::CONTENT_TYPE, $form['id']);
        return $this->jsonResult(
            ['id' => $form['id'], 'total' => count($versions), 'versions' => $versions],
            ['id' => $form['id'], 'versions' => count($versions)]
        );
    }
}
