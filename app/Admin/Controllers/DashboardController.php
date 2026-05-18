<?php

declare(strict_types=1);

namespace MidexCMS\Admin\Controllers;

use MidexCMS\Core\Admin\AdminNavigation;
use MidexCMS\Core\Admin\AdminView;
use MidexCMS\Core\Auth;
use MidexCMS\Core\Csrf;
use MidexCMS\Core\Database;
use MidexCMS\Core\Flash;
use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Core\SettingsService;
use MidexCMS\Modules\Analytics\AnalyticsService;

final class DashboardController
{
    public function __construct(
        private readonly Database $database,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly SettingsService $settings,
        private readonly AnalyticsService $analytics,
        private readonly AdminView $view,
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        if (!$this->auth->check()) {
            $this->flash->error('Please sign in to access the admin dashboard.');

            return Response::redirect('/admin/login');
        }

        $stats = [
            ['label' => 'Total pages', 'value' => (string) $this->count('pages', 'deleted_at IS NULL'), 'tone' => 'warm'],
            ['label' => 'Published pages', 'value' => (string) $this->count('pages', "deleted_at IS NULL AND status = 'published'"), 'tone' => 'cool'],
            ['label' => 'Media items', 'value' => (string) $this->count('media'), 'tone' => 'muted'],
            ['label' => 'Pending comments', 'value' => (string) $this->count('comments', "status = 'pending'"), 'tone' => 'alert'],
        ];
        $analytics = $this->analytics->dashboard();
        $site = $this->settings->siteContext()->toArray();
        $content = $this->view->renderDashboardPage(
            $analytics,
            (string) $site['theme']['active'],
            $this->csrf->inputName(),
            $this->csrf->token(),
        );

        return Response::html($this->view->layout('Dashboard', $content, AdminNavigation::items(), $this->flash->pull(), $stats));
    }

    private function count(string $table, ?string $where = null): int
    {
        $sql = 'SELECT COUNT(*) AS aggregate FROM ' . $table;

        if ($where !== null) {
            $sql .= ' WHERE ' . $where;
        }

        $row = $this->database->selectOne($sql);

        return (int) ($row['aggregate'] ?? 0);
    }

}
