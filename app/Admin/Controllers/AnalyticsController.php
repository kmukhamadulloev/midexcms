<?php

declare(strict_types=1);

namespace PlainCMS\Admin\Controllers;

use PlainCMS\Core\Admin\AdminNavigation;
use PlainCMS\Core\Admin\AdminView;
use PlainCMS\Core\Auth;
use PlainCMS\Core\Flash;
use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Modules\Analytics\AnalyticsService;

final class AnalyticsController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Flash $flash,
        private readonly AnalyticsService $analytics,
        private readonly AdminView $view
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access analytics.');

            return Response::redirect('/admin/login');
        }

        $data = $this->analytics->dashboard();
        $stats = [
            ['label' => 'Tracked views', 'value' => (string) $data['totals']['views'], 'tone' => 'warm'],
            ['label' => 'Unique visitors', 'value' => (string) $data['totals']['unique_visitors'], 'tone' => 'cool'],
            ['label' => 'Week views', 'value' => (string) $data['current_week']['views'], 'tone' => 'muted'],
            ['label' => 'Week delta', 'value' => ($data['weekly_delta']['views'] >= 0 ? '+' : '') . (string) $data['weekly_delta']['views'], 'tone' => 'alert'],
        ];

        $content = $this->view->renderAnalyticsPage(
            $data,
            $this->dailyChartConfig($data['daily']),
            $this->hourlyChartConfig($data['hourly']),
            $this->deviceChartConfig($data['device_breakdown']),
        );

        return Response::html($this->view->layout('Analytics', $content, AdminNavigation::items(), $this->flash->pull(), $stats));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function dailyChartConfig(array $rows): array
    {
        $labels = [];
        $views = [];
        $unique = [];

        foreach (array_reverse($rows) as $row) {
            $labels[] = (string) ($row['stat_date'] ?? '');
            $views[] = (int) ($row['views'] ?? 0);
            $unique[] = (int) ($row['unique_views'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => [
                ['label' => 'Views', 'values' => $views, 'color' => '#1df7ff'],
                ['label' => 'Unique', 'values' => $unique, 'color' => '#ff2bd6'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function hourlyChartConfig(array $rows): array
    {
        $labels = [];
        $views = [];

        foreach ($rows as $row) {
            $labels[] = substr((string) ($row['label'] ?? ''), 11, 5);
            $views[] = (int) ($row['views'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => [
                ['label' => 'Views', 'values' => $views, 'color' => '#8d5cff'],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function deviceChartConfig(array $rows): array
    {
        $labels = [];
        $values = [];
        $palette = ['#1df7ff', '#ff2bd6', '#ffd166', '#42ff87', '#8d5cff'];

        foreach (array_values($rows) as $index => $row) {
            $labels[] = ucfirst((string) ($row['device_type'] ?? 'unknown'));
            $values[] = [
                'value' => (int) ($row['views'] ?? 0),
                'color' => $palette[$index % count($palette)],
            ];
        }

        return [
            'labels' => $labels,
            'segments' => $values,
        ];
    }

}
