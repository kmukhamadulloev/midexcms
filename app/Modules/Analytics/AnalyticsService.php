<?php

declare(strict_types=1);

namespace MidexCMS\Modules\Analytics;

final class AnalyticsService
{
    public function __construct(
        private readonly AnalyticsRepository $analytics
    ) {
    }

    /**
     * @param array<string, mixed> $page
     */
    public function recordPageView(array $page, string $path, string $ipAddress, string $userAgent, ?string $referer = null): void
    {
        $visitorHash = sha1($ipAddress . '|' . $userAgent);
        $date = date('Y-m-d');
        $isUnique = !$this->analytics->uniqueViewExistsForDay((int) $page['id'], $visitorHash, $date);

        $this->analytics->recordView([
            'page_id' => (int) $page['id'],
            'path' => $path,
            'visitor_hash' => $visitorHash,
            'referer' => $referer !== '' ? $referer : null,
            'user_agent_hash' => sha1($userAgent),
            'device_type' => $this->deviceType($userAgent),
        ]);
        $this->analytics->upsertDailyStat((int) $page['id'], $date, $isUnique);
        $this->analytics->incrementPageViews((int) $page['id']);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(): array
    {
        $currentWeek = $this->analytics->totalsForDays(7);
        $previousWeek = $this->analytics->totalsForDays(14);
        $previousWeekViews = max(0, (int) $previousWeek['views'] - (int) $currentWeek['views']);
        $previousWeekUnique = max(0, (int) $previousWeek['unique_visitors'] - (int) $currentWeek['unique_visitors']);

        return [
            'totals' => $this->analytics->totals(),
            'top_content' => $this->analytics->topContent(),
            'daily' => $this->analytics->dailyStats(),
            'daily_3d' => $this->analytics->dailyStats(3),
            'daily_7d' => $this->analytics->dailyStats(7),
            'current_week' => $currentWeek,
            'weekly_delta' => [
                'views' => (int) $currentWeek['views'] - $previousWeekViews,
                'unique_visitors' => (int) $currentWeek['unique_visitors'] - $previousWeekUnique,
            ],
            'top_window_content' => $this->analytics->topContentByWindow(),
            'top_week_content' => $this->analytics->topContentByWindow(7, 5),
            'device_breakdown' => $this->analytics->deviceBreakdown(),
            'hourly' => $this->analytics->hourlyStats(),
            'recent_views' => $this->mapRecentViews($this->analytics->recentViews()),
            'top_referrers' => $this->mapReferrers($this->analytics->topReferrers()),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRecentViews(array $rows): array
    {
        return array_map(function (array $row): array {
            $referer = trim((string) ($row['referer'] ?? ''));
            $host = $referer !== '' ? (parse_url($referer, PHP_URL_HOST) ?: $referer) : 'Direct';

            return [
                'title' => (string) ($row['title'] ?? $row['path'] ?? 'Untitled'),
                'path' => (string) ($row['path'] ?? ''),
                'device_type' => (string) ($row['device_type'] ?? 'unknown'),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'referer_host' => (string) $host,
            ];
        }, $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapReferrers(array $rows): array
    {
        return array_map(function (array $row): array {
            $referer = trim((string) ($row['referer'] ?? ''));
            $host = $referer !== '' ? (parse_url($referer, PHP_URL_HOST) ?: $referer) : 'Direct';

            return [
                'host' => (string) $host,
                'visits' => (int) ($row['visits'] ?? 0),
            ];
        }, $rows);
    }

    private function deviceType(string $userAgent): ?string
    {
        $agent = strtolower($userAgent);

        if ($agent === '' || $agent === 'unknown') {
            return null;
        }

        if (str_contains($agent, 'mobile')) {
            return 'mobile';
        }

        if (str_contains($agent, 'tablet') || str_contains($agent, 'ipad')) {
            return 'tablet';
        }

        return 'desktop';
    }
}
