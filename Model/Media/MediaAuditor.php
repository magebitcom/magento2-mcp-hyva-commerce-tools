<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpHyvaCommerceTools\Model\Media;

use Magebit\McpHyvaCommerceTools\Model\Content\ContentScopeFilter;

/**
 * Media health over referenced images, scoped to Hyvä CMS, menus or all storefront content.
 *
 * For a caller restricted to some websites/store views only usages in content it may see count: images used only
 * elsewhere are not checked, and the usages left out are counted in usages_hidden_by_permissions.
 */
class MediaAuditor
{
    public const SCOPES = [
        'all' => [],
        'cms' => [ImageUsageIndex::SOURCE_HYVA_CMS],
        'menu' => [ImageUsageIndex::SOURCE_MENU],
    ];

    /**
     * @param ImageUsageIndex $usageIndex
     * @param ImageChecker $imageChecker
     * @param MediaService $mediaService
     * @param ContentScopeFilter $scopeFilter
     */
    public function __construct(
        private readonly ImageUsageIndex $usageIndex,
        private readonly ImageChecker $imageChecker,
        private readonly MediaService $mediaService,
        private readonly ContentScopeFilter $scopeFilter
    ) {
    }

    /**
     * Audit.
     *
     * @param string $scope all|cms|menu
     * @param int $maxKb
     * @param int $limit Max images listed (all images are counted).
     * @return array
     * @phpstan-return array{scope: string, images_checked: int, counts: array<string, int>,
     *     config_issues: list<array{severity: string, code: string, message: string}>, images: list<array<string,
     *     mixed>>, usages_hidden_by_permissions?: int}
     */
    public function audit(string $scope = 'all', int $maxKb = ImageChecker::DEFAULT_MAX_KB, int $limit = 100): array
    {
        $sources = self::SCOPES[$scope] ?? [];
        $outputFormats = $this->mediaService->getOutputFormats();
        $counts = [];
        $images = [];
        $checked = 0;
        $bySource = [];
        foreach ($this->usageIndex->all() as $path => $allUsages) {
            $bySource[(string) $path] = $sources === []
                ? $allUsages
                : array_values(
                    array_filter($allUsages, static fn (array $u): bool => in_array($u['source'], $sources, true))
                );
        }
        $scoped = $this->scopeFilter->filterGrouped($bySource);
        foreach ($scoped['visible'] as $path => $usages) {
            if ($usages === []) {
                continue;
            }
            $checked++;
            $result = $this->imageChecker->check((string) $path, $usages, [
                'max_kb' => $maxKb,
                'variants' => $this->mediaService->getVariants((string) $path),
                'output_formats' => $outputFormats,
            ]);
            foreach ($result['issues'] as $issue) {
                $counts[$issue['code']] = ($counts[$issue['code']] ?? 0) + 1;
            }
            $actionable = array_values(
                array_filter($result['issues'], static fn (array $i): bool => $i['severity'] !== 'info')
            );
            if ($actionable !== []) {
                $images[] = [
                    'path' => $path,
                    'size_kb' => $result['file']['size'] !== null ? (int) round($result['file']['size'] / 1024) : null,
                    'dimensions' => $result['file']['width'] !== null
                        ? $result['file']['width'] . 'x' . $result['file']['height']
                        : null,
                    'usages' => count($usages),
                    'used_in' => array_values(
                        array_unique(
                            array_map(
                                static fn (array $u): string => $u['entity_type'] . ':' . $u['identifier'],
                                $usages
                            )
                        )
                    ),
                    'issues' => $result['issues'],
                ];
            }
        }
        $rank = static fn (array $image): int => in_array(
            'error',
            array_column($image['issues'], 'severity'),
            true
        ) ? 0 : 1;
        usort($images, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));
        ksort($counts);

        $report = [
            'scope' => $scope,
            'images_checked' => $checked,
            'counts' => $counts,
            'config_issues' => $this->configIssues(),
            'images' => array_slice($images, 0, $limit),
        ];
        if ($this->scopeFilter->isRestricted()) {
            $report['usages_hidden_by_permissions'] = array_sum($scoped['hidden']);
        }
        return $report;
    }

    /**
     * Config issues.
     *
     * @return array
     * @phpstan-return list<array{severity: string, code: string, message: string}>
     */
    private function configIssues(): array
    {
        $settings = $this->mediaService->getSettings();
        if ($settings === null) {
            return [
                [
                    'severity' => 'info',
                    'code' => 'optimization_unavailable',
                    'message' => 'Hyvä Media Optimization is not available; images are served unoptimized.',
                ]
            ];
        }
        $issues = [];
        if (empty($settings['enabled'])) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'optimization_disabled',
                'message' => 'Media Optimization is installed but disabled.',
            ];
        }
        if (empty($settings['automatic_replacement'])) {
            $issues[] = [
                'severity' => 'warning',
                'code' => 'automatic_replacement_disabled',
                'message' => 'Automatic <img> replacement is off; only templates that call the Media API get '
                    . 'optimized images.',
            ];
        }
        return $issues;
    }
}
