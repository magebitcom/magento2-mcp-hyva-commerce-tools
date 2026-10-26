<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Tool;

use Hyva\CmsConnect\Exception\ConflictException;
use Hyva\CmsConnect\Exception\UnprocessableContentException;
use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpHyvaCommerceTools\Exception\FeatureUnavailableException;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\SecurityViolationException;

/**
 * Shared plumbing for every Hyvä Commerce MCP tool: name/ACL from class constants,
 * typed argument readers and JSON result encoding.
 */
abstract class AbstractTool implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = '';
    public const ACL_RESOURCE = '';
    public const UNDERLYING_ACL_RESOURCE = 'Hyva_Cms::cms';
    public const IS_WRITE = false;

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return static::TOOL_NAME;
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return static::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getUnderlyingAclResource(): ?string
    {
        return static::UNDERLYING_ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getWriteMode(): WriteMode
    {
        return static::IS_WRITE ? WriteMode::WRITE : WriteMode::READ;
    }

    /**
     * @inheritDoc
     */
    public function getConfirmationRequired(): bool
    {
        return static::IS_WRITE;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): ToolResultInterface
    {
        try {
            return $this->run($arguments);
        } catch (FeatureUnavailableException $e) {
            return $this->featureUnavailable($e->getPayload());
        } catch (UnprocessableContentException $e) {
            // Hyvä 1.4: a content provider refused the save or publish (HTTP 422 in the editor).
            return $this->softError($e->getMessage(), ['status' => 422, 'field_errors' => $e->getFieldErrors()]);
        } catch (ConflictException $e) {
            return $this->softError($e->getMessage(), [
                'status' => 409,
                'next_step' => 'Read the current draft again (get tool with fields ["draft"]) and re-apply your change '
                    . 'to it, passing its version uid as base_version_uid. Do not resubmit the content you read '
                    . 'before.',
            ]);
        } catch (AuthorizationException $e) {
            return $this->softError($e->getMessage(), ['status' => 403]);
        } catch (SecurityViolationException $e) {
            // Hyvä's ContentDocumentGuard: size (3 MB), node count (5000), depth (50), uid format, unknown components.
            return $this->softError($e->getMessage(), ['status' => 422]);
        }
    }

    /**
     * Tool body; an optional feature found missing on the way surfaces as the soft "not available" result.
     *
     * @param array $arguments
     * @return ToolResultInterface
     * @throws ConflictException When a draft changed since the caller read it.
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    abstract protected function run(array $arguments): ToolResultInterface;

    /**
     * Encode a payload as one pretty-printed JSON text block.
     *
     * @param array $payload
     * @param array $auditSummary
     * @param bool $isError
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<mixed> $payload
     * @phpstan-param array<string, mixed> $auditSummary
     */
    protected function jsonResult(array $payload, array $auditSummary = [], bool $isError = false): ToolResultInterface
    {
        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode the %1 result as JSON.', static::TOOL_NAME));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            isError: $isError,
            auditSummary: $auditSummary
        );
    }

    /**
     * Soft error: the call reached the tool but cannot be completed as asked.
     *
     * The audit log gets a stable code, never the message: messages can repeat caller input (an email address, a
     * path) and the audit summary is kept for every call.
     *
     * @param string $message
     * @param array $details
     * @param string $code Stable error code for the audit log; default status_<status> or soft_error
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $details
     */
    protected function softError(string $message, array $details = [], string $code = ''): ToolResultInterface
    {
        if ($code === '') {
            $status = $details['status'] ?? null;
            $code = is_int($status) ? 'status_' . $status : 'soft_error';
        }
        return $this->jsonResult(['error' => $message] + $details, ['error' => $code], true);
    }

    /**
     * Soft error returned when an optional Hyvä feature is missing or disabled.
     *
     * @param array $unavailable Output of FeatureCheckerInterface::getUnavailableResponse()
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $unavailable
     */
    protected function featureUnavailable(array $unavailable): ToolResultInterface
    {
        return $this->jsonResult(
            $unavailable,
            ['available' => false, 'feature' => $unavailable['feature'] ?? ''],
            true
        );
    }

    /**
     * Preview returned by destructive tools when `confirm: true` is missing.
     *
     * @param string $action
     * @param array $affected
     * @return ToolResultInterface
     * @throws LocalizedException
     * @phpstan-param array<mixed> $affected
     */
    protected function confirmationRequired(string $action, array $affected): ToolResultInterface
    {
        return $this->jsonResult(
            [
                'dry_run' => true,
                'confirmation_required' => true,
                'action' => $action,
                'affected' => $affected,
                'hint' => 'Nothing was changed. Call again with "confirm": true to apply.',
            ],
            ['dry_run' => true, 'action' => $action]
        );
    }

    /**
     * Int arg.
     *
     * @param array $arguments
     * @param string $key
     * @param int|null $default
     * @return int|null
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function intArg(array $arguments, string $key, ?int $default = null): ?int
    {
        $value = $arguments[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * String arg.
     *
     * @param array $arguments
     * @param string $key
     * @param string|null $default
     * @return string|null
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function stringArg(array $arguments, string $key, ?string $default = null): ?string
    {
        $value = $arguments[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $default;
    }

    /**
     * Bool arg.
     *
     * @param array $arguments
     * @param string $key
     * @param bool $default
     * @return bool
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function boolArg(array $arguments, string $key, bool $default = false): bool
    {
        $value = $arguments[$key] ?? null;
        return is_bool($value) ? $value : $default;
    }

    /**
     * Array arg.
     *
     * @param array $arguments
     * @param string $key
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return array<mixed>
     */
    protected function arrayArg(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /**
     * Int list arg.
     *
     * @param array $arguments
     * @param string $key
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<int>
     */
    protected function intListArg(array $arguments, string $key): array
    {
        $result = [];
        foreach ($this->arrayArg($arguments, $key) as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $result[] = (int) $value;
            }
        }
        return $result;
    }

    /**
     * String list arg.
     *
     * @param array $arguments
     * @param string $key
     * @return array
     * @phpstan-param array<string, mixed> $arguments
     * @phpstan-return list<string>
     */
    protected function stringListArg(array $arguments, string $key): array
    {
        $result = [];
        foreach ($this->arrayArg($arguments, $key) as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }
        return $result;
    }

    /**
     * Require int.
     *
     * @param array $arguments
     * @param string $key
     * @return int
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function requireInt(array $arguments, string $key): int
    {
        $value = $this->intArg($arguments, $key);
        if ($value === null) {
            throw new LocalizedException(__('Argument "%1" is required and must be an integer.', $key));
        }
        return $value;
    }

    /**
     * Require string.
     *
     * @param array $arguments
     * @param string $key
     * @return string
     * @throws LocalizedException
     * @phpstan-param array<string, mixed> $arguments
     */
    protected function requireString(array $arguments, string $key): string
    {
        $value = $this->stringArg($arguments, $key);
        if ($value === null) {
            throw new LocalizedException(__('Argument "%1" is required and must be a non-empty string.', $key));
        }
        return $value;
    }
}
