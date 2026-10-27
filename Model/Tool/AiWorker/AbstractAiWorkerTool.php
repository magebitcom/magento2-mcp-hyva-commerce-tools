<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool\AiWorker;

use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\McpHyvaCommerceTools\Model\Auth\CallerContext;
use Magebit\McpHyvaCommerceTools\Api\Adapter\AiWorkerGatewayInterface;
use Magebit\McpHyvaCommerceTools\Api\FeatureCheckerInterface;
use Magebit\McpHyvaCommerceTools\Model\Tool\AbstractTool;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Base for the Hyvä AI Worker monitoring tools (read only).
 *
 * Hyvä's job services check no permissions; its admin applies Hyva_AiWorker::jobs (every job) and
 * Hyva_AiWorker::manage (own jobs only, Job/Status controller). The tools apply the same split.
 */
abstract class AbstractAiWorkerTool extends AbstractTool
{
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_AiWorker::jobs';
    public const ALL_JOBS_RESOURCE = 'Hyva_AiWorker::jobs';

    /**
     * @param FeatureCheckerInterface $featureChecker
     * @param AiWorkerGatewayInterface $aiWorker
     * @param AuthorizationInterface $authorization
     * @param CallerContext $callerContext
     */
    public function __construct(
        protected readonly FeatureCheckerInterface $featureChecker,
        protected readonly AiWorkerGatewayInterface $aiWorker,
        protected readonly AuthorizationInterface $authorization,
        protected readonly CallerContext $callerContext
    ) {
    }

    /**
     * Hyvä resource the tool is listed with (system access while the module is disabled).
     *
     * @return string|null
     */
    public function getUnderlyingAclResource(): ?string
    {
        return $this->featureChecker->getStatus(FeatureCheckerInterface::AI_WORKER)['enabled']
            ? static::UNDERLYING_ACL_RESOURCE
            : 'Magento_Backend::system';
    }

    /**
     * @inheritDoc
     */
    protected function run(array $arguments): ToolResultInterface
    {
        // Module check only: the feature's ACL (all jobs) is narrower than what own-job tools need.
        if (!$this->featureChecker->getStatus(FeatureCheckerInterface::AI_WORKER)['enabled']) {
            return $this->featureUnavailable(
                $this->featureChecker->getUnavailableResponse(FeatureCheckerInterface::AI_WORKER)
            );
        }
        return $this->executeAiWorker($arguments);
    }

    /**
     * Tool body once AI Worker is enabled.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function executeAiWorker(array $arguments): ToolResultInterface;

    /**
     * Whether the caller may see every admin's jobs (otherwise only their own).
     *
     * @return bool
     */
    protected function seesAllJobs(): bool
    {
        return $this->authorization->isAllowed(self::ALL_JOBS_RESOURCE);
    }

    /**
     * Admin user id of the MCP caller.
     *
     * @return int|null
     */
    protected function adminUserId(): ?int
    {
        return $this->callerContext->adminUserId();
    }
}
