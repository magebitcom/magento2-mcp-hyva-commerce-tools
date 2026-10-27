<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Version;

use Hyva\CmsLiveviewEditor\Model\VersionHistory\VersionHistoryManager;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;

/**
 * `hyva_form.version.restore`.
 */
class VersionRestore extends AbstractFormTool
{
    public const TOOL_NAME = 'hyva_form.version.restore';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_version_restore';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_save';
    public const IS_WRITE = true;

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param StoreScopeGuard $storeScopeGuard
     * @param VersionHistoryManager $versionHistoryManager
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        FormGatewayInterface $formGateway,
        ContentService $contentService,
        FormRules $formRules,
        StoreScopeGuard $storeScopeGuard,
        private readonly VersionHistoryManager $versionHistoryManager
    ) {
        parent::__construct($featureChecker, $formGateway, $contentService, $formRules, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Restore Hyvä Form Version';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Restore an older form version as the new DRAFT (nothing goes live until hyva_form.form.publish).';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $schema = $this->withFormTarget(Schema::object())
            ->integer('version_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->toArray();
        $schema['required'] = ['version_id'];
        return $schema;
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateFormForWrite($arguments);
        $versionId = $this->intArg($arguments, 'version_id') ?? 0;
        $result = $this->versionHistoryManager->restoreVersion(self::CONTENT_TYPE, $form['id'], $versionId);
        if (empty($result['success'])) {
            return $this->softError(is_string($result['error'] ?? null) ? $result['error'] : 'Restore failed.');
        }
        return $this->jsonResult(
            ['restored' => true, 'id' => $form['id'], 'restored_version_id' => $versionId, 'published' => false],
            ['id' => $form['id'], 'version_id' => $versionId]
        );
    }
}
