<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Tool\HyvaForm\Form;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\McpHyvaCommerceTools\Api\Adapter\FormGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Content\ContentService;
use Magebit\McpHyvaCommerceTools\Model\Content\DeletionUsage;
use Magebit\McpHyvaCommerceTools\Model\Form\FormRules;
use Magebit\McpHyvaCommerceTools\Model\Security\StoreScopeGuard;
use Magebit\McpHyvaCommerceTools\Model\Tool\Entity\InUseDeleteGate;
use Magebit\McpHyvaCommerceTools\Model\Tool\Form\AbstractFormTool;

/**
 * `hyva_form.form.delete` MCP tool: confirm-gated delete of one form (T-181, the T-22 delete pattern).
 *
 * Needs the tool's own resource ("Delete Hyvä Forms via MCP", granted to no role by default) and the Form Builder
 * delete permission the admin delete action uses; the core checks both. The store scope write rule applies: every
 * store view the form is assigned to must be the role's. A form still in use (a form widget naming its identifier) is
 * deleted only with "force": true next to "confirm": true (InUseDeleteGate). Forms cannot be scheduled in Form
 * Builder 1.0, so there are no pending scheduled items to cancel.
 */
class FormDelete extends AbstractFormTool
{
    use InUseDeleteGate;

    public const TOOL_NAME = 'hyva_form.form.delete';
    public const ACL_RESOURCE = 'Magebit_McpHyvaCommerceTools::tool_hyva_form_form_delete';
    /** Hyva\FormBuilder\Controller\Adminhtml\Forms\Delete::ADMIN_RESOURCE */
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_FormBuilder::form_delete';
    public const IS_WRITE = true;

    /**
     * What deleting a form in use does to its widgets.
     */
    private const IN_USE_CONSEQUENCE = 'a form widget that names a deleted form renders nothing.';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param FormGatewayInterface $formGateway
     * @param ContentService $contentService
     * @param FormRules $formRules
     * @param StoreScopeGuard $storeScopeGuard
     * @param DeletionUsage $deletionUsage
     */
    public function __construct(
        FeatureCheckerInterface $featureChecker,
        FormGatewayInterface $formGateway,
        ContentService $contentService,
        FormRules $formRules,
        StoreScopeGuard $storeScopeGuard,
        private readonly DeletionUsage $deletionUsage
    ) {
        parent::__construct($featureChecker, $formGateway, $contentService, $formRules, $storeScopeGuard);
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return 'Delete Hyvä Form';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Delete a form permanently, from every store view it is assigned to, with its draft, version history '
            . 'and Tailwind CSS. Without "confirm": true nothing is deleted and the reply previews the form, its store '
            . 'views and where its identifier is still used (details.usage: hyva_content = form widgets in Hyvä CMS '
            . 'content, magento_content = form widget directives in Magento content, widget_instances = widget '
            . 'instances). A form in use is deleted only with "force": true as well as "confirm": true (a form widget '
            . 'that names a deleted form renders nothing; the result then says "forced": true and repeats the usage); '
            . 'tell the user where it is used before confirming. When other forms share the identifier on other '
            . 'store views, details.identifier_shared_with lists them: the widgets render those forms there. Needs '
            . 'the MCP permission for this tool plus the admin delete permission ' . self::UNDERLYING_ACL_RESOURCE
            . '. A role restricted to some websites can delete only a form whose store views are all its own. To '
            . 'take a form off the storefront without deleting it, the user switches it off in the Form Builder '
            . 'admin.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return $this->withFormTarget(Schema::object())
            ->boolean('confirm', fn (BooleanBuilder $b) => $b->description(
                'Delete, after reviewing the preview. Without it nothing is deleted.'
            ))
            ->boolean('force', fn (BooleanBuilder $b) => $b->description(
                'Also delete when the preview says it is still in use (in_use: true); needs "confirm": true as '
                    . 'well. Default false: a form in use is refused. Not needed when it is not in use.'
            ))
            ->toArray();
    }

    /**
     * @inheritDoc
     */
    protected function executeForm(array $arguments): ToolResultInterface
    {
        $form = $this->locateFormForWrite($arguments);
        $usage = $this->deletionUsage->forForm($form['identifier']);
        $inUse = ($usage['used'] ?? false) === true;
        $summary = [
            'entity_type' => 'form',
            'id' => $form['id'],
            'identifier' => $form['identifier'],
            'title' => $form['title'],
            'is_active' => $form['is_active'],
            'store_ids' => $form['store_ids'],
            'has_published' => $form['has_published'],
            'in_use' => $inUse,
        ];
        $shared = $this->sharedWith($form['id'], $form['identifier']);
        if (!$this->boolArg($arguments, 'confirm')) {
            return $this->confirmationRequired(
                'delete_form',
                [$summary],
                ['in_use' => $inUse, 'force_required' => $inUse, 'usage' => $usage]
                    + ($shared !== [] ? ['identifier_shared_with' => $shared] : []),
                null,
                'Deleting removes the form from every store view listed, with its draft and version history; it '
                    . 'cannot be undone.'
                    . ($shared !== [] ? sprintf(
                        ' Form(s) #%s use the same identifier on other store views (details.identifier_shared_with); '
                            . 'widgets naming it keep rendering those there.',
                        implode(', #', array_column($shared, 'id'))
                    ) : '')
                    . ($inUse ? $this->inUseHint(self::IN_USE_CONSEQUENCE) : '')
            );
        }
        if ($inUse && !$this->boolArg($arguments, 'force')) {
            return $this->inUseRefusal(
                sprintf('Form "%s" (#%d)', $form['identifier'], $form['id']),
                $usage,
                self::IN_USE_CONSEQUENCE
            );
        }
        $this->formGateway->delete($form['id']);
        $audit = ['entity_type' => 'form', 'id' => $form['id'], 'deleted' => true, 'in_use' => $inUse];
        if (!$inUse) {
            return $this->jsonResult(['deleted' => true] + $summary, $audit);
        }
        return $this->jsonResult(
            ['deleted' => true] + $summary + $this->forcedDeleteDetails($usage),
            $audit + ['forced' => true]
        );
    }

    /**
     * Other forms with the same identifier (Hyvä allows one per store view) the caller may read, as id and store views
     *
     * @param int $id
     * @param string $identifier
     * @return array
     * @phpstan-return list<array{id: int, store_ids: list<int>}>
     */
    private function sharedWith(int $id, string $identifier): array
    {
        $shared = [];
        foreach ($this->formGateway->findByIdentifier($identifier) as $other) {
            if ($other['id'] !== $id && $this->storeScopeGuard->allowsRead($other['store_ids'])) {
                $shared[] = ['id' => $other['id'], 'store_ids' => $other['store_ids']];
            }
        }
        return $shared;
    }
}
