<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Factory for creating Chart.js charts used in statistics pages.
 */
class ChartFactory
{
    // Color palette matching the existing CSS design
    private const CATEGORY_COLORS = [
        'Lights' => '#fbbf24',
        'Climate' => '#3b82f6',
        'Sensors' => '#10b981',
        'Security' => '#ef4444',
        'Appliances' => '#8b5cf6',
        'Entertainment' => '#ec4899',
        'Energy' => '#f97316',
        'System' => '#6b7280',
    ];

    private const PRIMARY_COLOR = '#2563eb';

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * Create a doughnut chart for category distribution.
     *
     * @param array<string, int> $categoryData Category name => count
     */
    public function createCategoryChart(array $categoryData, bool $excludeSystem = true): Chart
    {
        if ($excludeSystem) {
            unset($categoryData['System']);
        }

        $labels = array_keys($categoryData);
        $data = array_values($categoryData);
        $colors = array_map(
            fn (string $cat) => self::CATEGORY_COLORS[$cat] ?? '#94a3b8',
            $labels
        );

        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
            ]],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => true,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => ['boxWidth' => 12, 'padding' => 15],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * Create a horizontal bar chart for top vendors.
     *
     * @param array<array{name: string, device_count: int}> $vendors
     */
    public function createVendorChart(array $vendors, int $limit = 10): Chart
    {
        $vendors = array_slice($vendors, 0, $limit);
        $labels = array_map(
            fn (array $v) => strlen($v['name']) > 15 ? substr($v['name'], 0, 15).'...' : $v['name'],
            $vendors
        );
        $data = array_column($vendors, 'device_count');

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Devices',
                'data' => $data,
                'backgroundColor' => self::PRIMARY_COLOR,
                'borderRadius' => 4,
            ]],
        ]);

        $chart->setOptions([
            'indexAxis' => 'y',
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => ['beginAtZero' => true, 'grid' => ['display' => false]],
                'y' => ['grid' => ['display' => false]],
            ],
        ]);

        return $chart;
    }

    /**
     * Create a bar chart for spec version distribution.
     *
     * @param array<string, int> $versionData Version => count
     */
    public function createSpecVersionChart(array $versionData): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_keys($versionData),
            'datasets' => [[
                'label' => 'Devices',
                'data' => array_values($versionData),
                'backgroundColor' => [
                    '#dbeafe', // blue-100
                    '#dcfce7', // green-100
                    '#fef3c7', // yellow-100
                    '#fce7f3', // pink-100
                ],
                'borderColor' => [
                    '#1d4ed8', // blue-700
                    '#15803d', // green-700
                    '#b45309', // yellow-700
                    '#be185d', // pink-700
                ],
                'borderWidth' => 1,
                'borderRadius' => 4,
            ]],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => true,
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => ['beginAtZero' => true, 'grid' => ['color' => '#f3f4f6']],
                'x' => ['grid' => ['display' => false]],
            ],
        ]);

        return $chart;
    }
}
