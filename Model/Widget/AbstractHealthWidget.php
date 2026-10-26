<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Widget;

use Hyva\AdminDashboardApi\Api\ConfigurationKeys;
use Hyva\AdminDashboardApi\Api\V1\WidgetContextInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetInstanceInterface;
use Hyva\AdminDashboardApi\Api\V1\WidgetTypeInterface;
use Magebit\McpHyvaCommerceTools\Model\Util\Cast;
use Magento\Framework\Phrase;

/**
 * Base for the Magebit MCP dashboard widgets. Every widget returns the same display shape, rendered by
 * Magebit_McpHyvaCommerceTools::widget/health-summary.phtml:
 * {stats: [{label, value, severity}], sections: [{title, rows: [{label, detail, badge, severity, url}], empty}],
 *  notice: string|null, links: [{label, url}], generated_at}
 */
abstract class AbstractHealthWidget implements WidgetTypeInterface
{
    /**
     * Widget-specific configurable properties (merged with the context defaults).
     *
     * @return array
     * @phpstan-return array<string, array<string, mixed>>
     */
    protected function widgetProperties(): array
    {
        return [];
    }

    /**
     * Build data.
     *
     * @param WidgetContextInterface $ctx
     * @param WidgetInstanceInterface $widgetInstance
     * @return array
     * @phpstan-return array<string, mixed>
     */
    abstract protected function buildData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): array;

    /**
     * @inheritDoc
     */
    public function getDisplayData(WidgetContextInterface $ctx, WidgetInstanceInterface $widgetInstance): mixed
    {
        return $this->buildData($ctx, $widgetInstance) + ['generated_at' => gmdate('Y-m-d H:i:s') . ' UTC'];
    }

    /**
     * @inheritDoc
     */
    public function getTitle(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): Phrase
    {
        return $ctx->getTitle();
    }

    /**
     * @inheritDoc
     */
    public function getConfigurableProperties(WidgetContextInterface $ctx): array
    {
        return array_merge($ctx->getConfigurableProperties(), $this->widgetProperties());
    }

    /**
     * @inheritDoc
     */
    public function getDisplayProperties(WidgetContextInterface $ctx): array
    {
        return $ctx->getDisplayProperties();
    }

    /**
     * @inheritDoc
     */
    public function getTrailingAction(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): array
    {
        return $ctx->getTrailingAction();
    }

    /**
     * @inheritDoc
     */
    public function isAllowed(WidgetContextInterface $ctx, ?WidgetInstanceInterface $widgetInstance): bool
    {
        return $ctx->isAllowed($widgetInstance);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave(
        WidgetContextInterface $ctx,
        WidgetInstanceInterface $widgetInstance
    ): WidgetInstanceInterface {
        return $widgetInstance;
    }

    /**
     * @inheritDoc
     */
    public function afterSave(
        WidgetContextInterface $ctx,
        WidgetInstanceInterface $widgetInstance
    ): WidgetInstanceInterface {
        return $widgetInstance;
    }

    /**
     * Int property.
     *
     * @param WidgetInstanceInterface $widgetInstance
     * @param string $name
     * @param int $default
     * @return int
     */
    protected function intProperty(WidgetInstanceInterface $widgetInstance, string $name, int $default): int
    {
        $value = $widgetInstance->getPropertyValue(ConfigurationKeys::CONFIGURABLE_PROPERTIES, $name);
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * Number input definition for a configurable property.
     *
     * @param string $label
     * @param int $default
     * @param int $max
     * @return array
     * @phpstan-return array<string, mixed>
     */
    protected function numberProperty(string $label, int $default, int $max): array
    {
        return ['label' => __($label), 'input' => ['type' => 'text', 'subtype' => 'number',
            'attributes' => ['value' => $default, 'min' => 1, 'max' => $max, 'required' => true]]];
    }

    /**
     * Stat.
     *
     * @param string $label
     * @param int|string $value
     * @param string|null $severity success|warning|danger
     * @return array
     * @phpstan-return array{label: string, value: int|string, severity: string|null}
     */
    protected function stat(string $label, int|string $value, ?string $severity = null): array
    {
        return ['label' => (string) __($label), 'value' => $value, 'severity' => $severity];
    }

    /**
     * Severity for a problem counter: 0 = success, otherwise the given level.
     *
     * @param int $count
     * @param string $level
     * @return string
     */
    protected function level(int $count, string $level = 'warning'): string
    {
        return $count === 0 ? 'success' : $level;
    }

    /**
     * Row.
     *
     * @param string $label
     * @param string|null $detail
     * @param string|null $badge
     * @param string|null $severity
     * @param string|null $url
     * @return array
     * @phpstan-return array{label: string, detail: string|null, badge: string|null, severity: string|null,
     *     url: string|null}
     */
    protected function row(
        string $label,
        ?string $detail = null,
        ?string $badge = null,
        ?string $severity = null,
        ?string $url = null
    ): array {
        return ['label' => $label, 'detail' => $detail, 'badge' => $badge, 'severity' => $severity, 'url' => $url];
    }

    /**
     * Map an issue severity to a widget badge severity.
     *
     * @param mixed $severity
     * @return string
     */
    protected function badgeSeverity(mixed $severity): string
    {
        return match (Cast::string($severity)) {
            'error' => 'danger',
            'warning' => 'warning',
            default => 'success',
        };
    }
}
