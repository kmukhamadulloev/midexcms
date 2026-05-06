<?php

declare(strict_types=1);

namespace PlainCMS\Modules\Analytics;

use PlainCMS\Core\Database;

final class AnalyticsRepository
{
    public function __construct(
        private readonly Database $database
    ) {
    }

    public function recordView(array $payload): void
    {
        $this->database->statement(
            'INSERT INTO page_views (page_id, path, visitor_hash, referer, user_agent_hash, device_type)
             VALUES (:page_id, :path, :visitor_hash, :referer, :user_agent_hash, :device_type)',
            $payload
        );
    }

    public function incrementPageViews(int $pageId): void
    {
        $this->database->statement(
            'UPDATE pages SET views_count = views_count + 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['id' => $pageId]
        );
    }

    public function uniqueViewExistsForDay(int $pageId, string $visitorHash, string $date): bool
    {
        return $this->database->selectOne(
            'SELECT id
             FROM page_views
             WHERE page_id = :page_id
               AND visitor_hash = :visitor_hash
               AND DATE(created_at) = :stat_date
             LIMIT 1',
            [
                'page_id' => $pageId,
                'visitor_hash' => $visitorHash,
                'stat_date' => $date,
            ]
        ) !== null;
    }

    public function upsertDailyStat(int $pageId, string $date, bool $unique): void
    {
        $this->database->statement(
            'INSERT INTO page_view_daily_stats (page_id, stat_date, views, unique_views)
             VALUES (:page_id, :stat_date, 1, :unique_views)
             ON CONFLICT (page_id, stat_date)
             DO UPDATE SET
                views = page_view_daily_stats.views + 1,
                unique_views = page_view_daily_stats.unique_views + :unique_views',
            [
                'page_id' => $pageId,
                'stat_date' => $date,
                'unique_views' => $unique ? 1 : 0,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topContent(int $limit = 10): array
    {
        return $this->database->select(
            'SELECT pages.id, pages.title, pages.path, pages.type, pages.views_count, pages.likes_count, pages.comments_count
             FROM pages
             WHERE pages.deleted_at IS NULL
               AND pages.status = :status
             ORDER BY pages.views_count DESC, pages.likes_count DESC, pages.id DESC
             LIMIT ' . (int) $limit,
            ['status' => 'published']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function dailyStats(int $days = 14): array
    {
        return $this->database->select(
            'SELECT stat_date, SUM(views) AS views, SUM(unique_views) AS unique_views
             FROM page_view_daily_stats
             WHERE stat_date >= CURRENT_DATE - INTERVAL \'' . (int) max(1, $days - 1) . ' days\'
             GROUP BY stat_date
             ORDER BY stat_date DESC'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        $views = $this->database->selectOne('SELECT COUNT(*) AS aggregate FROM page_views');
        $unique = $this->database->selectOne('SELECT COUNT(DISTINCT visitor_hash) AS aggregate FROM page_views WHERE visitor_hash IS NOT NULL');

        return [
            'views' => (int) ($views['aggregate'] ?? 0),
            'unique_visitors' => (int) ($unique['aggregate'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function totalsForDays(int $days): array
    {
        $window = max(1, $days - 1);
        $row = $this->database->selectOne(
            'SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS unique_visitors
             FROM page_views
             WHERE created_at >= CURRENT_DATE - INTERVAL \'' . $window . ' days\''
        );

        return [
            'views' => (int) ($row['views'] ?? 0),
            'unique_visitors' => (int) ($row['unique_visitors'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topContentByWindow(int $days = 30, int $limit = 8): array
    {
        return $this->database->select(
            'SELECT pages.id, pages.title, pages.path, pages.type,
                    COUNT(page_views.id) AS views,
                    COUNT(DISTINCT page_views.visitor_hash) AS unique_visitors,
                    MAX(page_views.created_at) AS last_view_at,
                    pages.likes_count,
                    pages.comments_count
             FROM page_views
             INNER JOIN pages ON pages.id = page_views.page_id
             WHERE pages.deleted_at IS NULL
               AND pages.status = :status
               AND page_views.created_at >= CURRENT_DATE - INTERVAL \'' . (int) max(1, $days - 1) . ' days\'
             GROUP BY pages.id, pages.title, pages.path, pages.type, pages.likes_count, pages.comments_count
             ORDER BY views DESC, unique_visitors DESC, pages.id DESC
             LIMIT ' . (int) $limit,
            ['status' => 'published']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deviceBreakdown(int $days = 30): array
    {
        return $this->database->select(
            'SELECT COALESCE(device_type, :unknown) AS device_type, COUNT(*) AS views
             FROM page_views
             WHERE created_at >= CURRENT_DATE - INTERVAL \'' . (int) max(1, $days - 1) . ' days\'
             GROUP BY COALESCE(device_type, :unknown)
             ORDER BY views DESC',
            ['unknown' => 'unknown']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentViews(int $limit = 12): array
    {
        return $this->database->select(
            'SELECT page_views.path, page_views.referer, page_views.device_type, page_views.created_at,
                    pages.title
             FROM page_views
             LEFT JOIN pages ON pages.id = page_views.page_id
             ORDER BY page_views.created_at DESC
             LIMIT ' . (int) $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function topReferrers(int $days = 30, int $limit = 8): array
    {
        return $this->database->select(
            'SELECT referer, COUNT(*) AS visits
             FROM page_views
             WHERE referer IS NOT NULL
               AND referer != \'\'
               AND created_at >= CURRENT_DATE - INTERVAL \'' . (int) max(1, $days - 1) . ' days\'
             GROUP BY referer
             ORDER BY visits DESC
             LIMIT ' . (int) $limit
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hourlyStats(int $hours = 24): array
    {
        return $this->database->select(
            'SELECT TO_CHAR(DATE_TRUNC(\'hour\', created_at), \'YYYY-MM-DD HH24:00\') AS label,
                    COUNT(*) AS views,
                    COUNT(DISTINCT visitor_hash) AS unique_visitors
             FROM page_views
             WHERE created_at >= NOW() - INTERVAL \'' . (int) max(1, $hours) . ' hours\'
             GROUP BY DATE_TRUNC(\'hour\', created_at)
             ORDER BY DATE_TRUNC(\'hour\', created_at) ASC'
        );
    }
}
