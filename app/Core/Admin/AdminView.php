<?php

declare(strict_types=1);

namespace PlainCMS\Core\Admin;

use JsonException;

final class AdminView
{
    private AdminTemplateRenderer $templates;
    private string $themeKey;

    public function __construct(?string $basePath = null, ?string $themeKey = null)
    {
        $resolvedBasePath = $basePath ?? dirname(__DIR__, 3) . '/content/themes/dashboard/default';
        $this->templates = new AdminTemplateRenderer($resolvedBasePath);
        $this->themeKey = $themeKey ?? basename($resolvedBasePath);
    }

    /**
     * @param array<int, array{label: string, href: string, section?: string, icon?: string, meta?: string}> $navigation
     * @param array<int, array{label: string, value: string, tone?: string}> $stats
     */
    public function layout(string $title, string $content, array $navigation = [], ?array $flash = null, array $stats = []): string
    {
        $currentPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH);
        $navHtml = '';
        $currentSection = null;

        foreach ($navigation as $item) {
            $href = (string) $item['href'];
            $section = (string) ($item['section'] ?? '');
            $isActive = $href === '/admin'
                ? $currentPath === '/admin'
                : $href !== '/' && ($currentPath === $href || str_starts_with($currentPath, rtrim($href, '/') . '/'));

            if ($section !== '' && $section !== $currentSection) {
                $currentSection = $section;
                $navHtml .= sprintf('<p class="nav-section-label">%s</p>', $this->escape($section));
            }

            $navHtml .= sprintf(
                '<a class="nav-link%s" href="%s"><span class="nav-link-icon">%s</span><span class="nav-link-copy"><strong class="nav-link-label">%s</strong><small class="nav-link-meta">%s</small></span></a>',
                $isActive ? ' is-active' : '',
                $this->escape($href),
                $this->escape((string) ($item['icon'] ?? '>>')),
                $this->escape($item['label']),
                $this->escape((string) ($item['meta'] ?? '')),
            );
        }

        $flashHtml = '';

        if ($flash !== null) {
            $flashHtml = sprintf(
                '<div class="%s">%s</div>',
                ($flash['type'] ?? 'success') === 'error' ? 'flash flash-error' : 'flash flash-success',
                $this->escape((string) ($flash['message'] ?? '')),
            );
        }

        $statsHtml = '';

        if ($stats !== []) {
            $cards = '';

            foreach ($stats as $stat) {
                $cards .= sprintf(
                    '<article class="stat-card stat-%s"><p class="stat-label">%s</p><strong class="stat-value">%s</strong></article>',
                    $this->escape((string) ($stat['tone'] ?? 'muted')),
                    $this->escape($stat['label']),
                    $this->escape($stat['value']),
                );
            }

            $statsHtml = '<section class="stats-grid">' . $cards . '</section>';
        }

        $shellMode = $navigation === [] ? 'auth' : 'app';

        if ($shellMode === 'auth') {
            return $this->templates->render('layouts/auth.html', [
                'title' => $title,
                'dashboard_theme_asset_base' => '/dashboard-theme-assets/' . rawurlencode($this->themeKey),
                'flash_html' => $flashHtml,
                'content' => $content,
            ]);
        }

        return $this->templates->render('layouts/app.html', [
            'title' => $title,
            'dashboard_theme_asset_base' => '/dashboard-theme-assets/' . rawurlencode($this->themeKey),
            'navigation_html' => $navHtml,
            'flash_html' => $flashHtml,
            'stats_html' => $statsHtml,
            'content' => $content,
        ]);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, array<int, string>> $errors
     * @param array<int, array{name: string, label: string, type?: string, value?: string, helper?: string}> $fields
     */
    public function authForm(string $title, string $subtitle, string $action, array $fields, string $csrfToken, string $submitLabel, array $old = [], array $errors = []): string
    {
        $fieldHtml = '';

        foreach ($fields as $field) {
            $name = $field['name'];
            $type = (string) ($field['type'] ?? 'text');
            $value = $type === 'password' ? '' : (string) ($old[$name] ?? ($field['value'] ?? ''));
            $fieldHtml .= $this->inputRow(
                $name,
                $field['label'],
                $type,
                $value,
                (string) ($field['helper'] ?? ''),
                $errors[$name][0] ?? '',
            );
        }

        return $this->templates->render('components/auth-form.html', [
            'title' => $title,
            'subtitle' => $subtitle,
            'action' => $action,
            'csrf_token' => $csrfToken,
            'submit_label' => $submitLabel,
            'fields_html' => $fieldHtml,
        ]);
    }

    /**
     * @param array<int, array{label: string, href: string}> $links
     */
    public function authLinks(array $links): string
    {
        $items = '';

        foreach ($links as $index => $link) {
            if ($index > 0) {
                $items .= '<span aria-hidden="true"> · </span>';
            }

            $items .= sprintf('<a href="%s">%s</a>', $this->escape($link['href']), $this->escape($link['label']));
        }

        return $this->templates->render('components/auth-links.html', [
            'links_html' => $items,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $analytics
     */
    public function renderDashboardPage(array $analytics, string $themeKey, string $csrfInputName, string $csrfToken): string
    {
        $primaryDevice = (string) ($analytics['device_breakdown'][0]['device_type'] ?? 'unknown');
        $welcomeBody = '<div class="hero-card neon-hero-card">'
            . '<p class="hero-eyebrow">Live site pulse</p>'
            . '<div class="hero-meta"><span class="badge badge-live">Primary device: ' . $this->escape($primaryDevice) . '</span><span class="badge badge-live">Theme: ' . $this->escape($themeKey) . '</span><span class="badge badge-live">CMS online</span><span class="badge badge-outline">Views this week: ' . (int) ($analytics['current_week']['views'] ?? 0) . '</span></div>'
            . '<div class="signal-strip">'
            . $this->signalStat('Weekly visitors', (string) (int) ($analytics['current_week']['unique_visitors'] ?? 0), (int) ($analytics['weekly_delta']['unique_visitors'] ?? 0))
            . $this->signalStat('Weekly views', (string) (int) ($analytics['current_week']['views'] ?? 0), (int) ($analytics['weekly_delta']['views'] ?? 0))
            . $this->signalStat('Top path', (string) ($analytics['top_window_content'][0]['path'] ?? '/'), null)
            . '</div>'
            . $this->recentEventsCard($analytics['recent_views'] ?? [])
            . '</div>';
        $overviewBody = '<div class="overview-shell"><section class="overview-primary">' . $welcomeBody . '</section></div>';
        $quickActionsBody = '<div class="quick-links">'
            . $this->quickLinkCard('/admin/pages/create', 'NP', 'New page', 'Start a landing page or content section.', 'Create')
            . $this->quickLinkCard('/admin/pages', 'PG', 'Open pages', 'Manage the full site tree from one place.', 'Manage')
            . $this->quickLinkCard('/admin/media', 'MD', 'Open media', 'Upload images and grab ready-to-paste snippets.', 'Library')
            . $this->quickLinkCard('/admin/settings', 'ST', 'Open settings', 'Switch public themes and adjust CMS settings.', 'Configure')
            . '</div>';
        $logoutForm = $this->actionForm('/admin/logout', 'Logout', $csrfInputName, $csrfToken, 'button-secondary', 'inline-form');

        return $this->templates->render('pages/dashboard.html', [
            'overview_panel_html' => $this->panel('Overview', $overviewBody),
            'traffic_panel_html' => $this->panel('Traffic monitor', $this->trafficMonitorCard($analytics)),
            'quick_actions_panel_html' => $this->panel('Quick actions', $quickActionsBody),
            'session_panel_html' => $this->panel('Session', '<p>Authenticated admin shell is live and ready for content updates.</p>' . $logoutForm),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderAnalyticsPage(array $data, array $dailyChartConfig, array $hourlyChartConfig, array $deviceChartConfig): string
    {
        $topContentRows = '';

        foreach ($data['top_content'] as $page) {
            $topContentRows .= '<tr><td><a href="' . $this->escape((string) $page['path']) . '" target="_blank">' . $this->escape((string) $page['title']) . '</a></td>'
                . '<td>' . $this->escape((string) $page['type']) . '</td>'
                . '<td>' . (int) $page['views_count'] . '</td>'
                . '<td>' . (int) $page['likes_count'] . '</td>'
                . '<td>' . (int) $page['comments_count'] . '</td></tr>';
        }

        $dailyRows = '';

        foreach ($data['daily'] as $day) {
            $dailyRows .= '<tr><td>' . $this->escape((string) $day['stat_date']) . '</td><td>' . (int) $day['views'] . '</td><td>' . (int) $day['unique_views'] . '</td></tr>';
        }

        $windowRows = '';

        foreach ($data['top_window_content'] as $page) {
            $windowRows .= '<tr><td><a href="' . $this->escape((string) $page['path']) . '" target="_blank">' . $this->escape((string) $page['title']) . '</a></td>'
                . '<td>' . (int) $page['views'] . '</td>'
                . '<td>' . (int) $page['unique_visitors'] . '</td>'
                . '<td>' . $this->escape((string) $page['last_view_at']) . '</td></tr>';
        }

        $refererItems = '';

        foreach ($data['top_referrers'] as $referer) {
            $refererItems .= '<article class="leader-item"><div><strong>' . $this->escape((string) $referer['host']) . '</strong><span>Referral source</span></div><b>' . (int) $referer['visits'] . '</b></article>';
        }

        $recentRows = '';

        foreach ($data['recent_views'] as $view) {
            $recentRows .= '<tr><td>' . $this->escape((string) $view['title']) . '</td>'
                . '<td><code>' . $this->escape((string) $view['path']) . '</code></td>'
                . '<td>' . $this->escape((string) $view['referer_host']) . '</td>'
                . '<td>' . $this->escape((string) $view['device_type']) . '</td>'
                . '<td>' . $this->escape((string) $view['created_at']) . '</td></tr>';
        }

        return $this->templates->render('pages/analytics-index.html', [
            'chart_cards_html' => $this->chartCard('Daily traffic', 'line', $dailyChartConfig)
                . $this->chartCard('24h pulse', 'bar', $hourlyChartConfig)
                . $this->chartCard('Device split', 'donut', $deviceChartConfig),
            'window_panel_html' => $this->panel('30-day content performance', $this->table(
                ['Title', 'Views', 'Unique', 'Last seen'],
                $windowRows,
                'No 30-day page activity yet.',
                4
            )),
            'referer_panel_html' => $this->panel('Referral sources', '<div class="leaderboard-list">' . ($refererItems !== '' ? $refererItems : '<p>No referral traffic captured yet.</p>') . '</div>'),
            'top_content_panel_html' => $this->panel('Top content snapshot', $this->table(
                ['Title', 'Type', 'Views', 'Likes', 'Comments'],
                $topContentRows,
                'No analytics data yet.',
                5
            )),
            'daily_panel_html' => $this->panel('Daily traffic table', $this->table(
                ['Date', 'Views', 'Unique views'],
                $dailyRows,
                'No daily stats yet.',
                3
            )),
            'recent_panel_html' => $this->panel('Recent view events', $this->table(
                ['Title', 'Path', 'Referer', 'Device', 'Seen at'],
                $recentRows,
                'No recent view events yet.',
                5
            ), '', 'panel analytics-wide'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $comments
     */
    public function renderCommentsPage(array $comments, string $csrfInputName, string $csrfToken): string
    {
        $rows = '';

        foreach ($comments as $comment) {
            $actions = $this->actionForm('/admin/comments/' . (int) $comment['id'] . '/approve', 'Approve', $csrfInputName, $csrfToken)
                . $this->actionForm('/admin/comments/' . (int) $comment['id'] . '/spam', 'Spam', $csrfInputName, $csrfToken)
                . $this->actionForm('/admin/comments/' . (int) $comment['id'] . '/delete', 'Delete', $csrfInputName, $csrfToken, 'link-button danger-link', 'inline-form action-form', 'Delete this comment?');

            $rows .= '<tr><td>' . $this->escape((string) $comment['author_name']) . '</td>'
                . '<td><a href="' . $this->escape((string) $comment['page_path']) . '" target="_blank">' . $this->escape((string) $comment['page_title']) . '</a></td>'
                . '<td>' . $this->badge((string) $comment['status']) . '</td>'
                . '<td>' . $this->escape((string) $comment['content']) . '</td>'
                . '<td>' . $actions . '</td></tr>';
        }

        return $this->templates->render('pages/comments-index.html', [
            'comments_panel_html' => $this->panel('', $this->table(
                ['Author', 'Page', 'Status', 'Comment', 'Actions'],
                $rows,
                'No comments yet.',
                5
            )),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $backups
     */
    public function renderBackupsPage(array $backups, string $csrfInputName, string $csrfToken): string
    {
        $rows = '';

        foreach ($backups as $backup) {
            $filesHtml = '';

            foreach ($backup['files'] as $file) {
                $filesHtml .= '<div><a href="/admin/backups/download/' . rawurlencode((string) $backup['name']) . '/' . rawurlencode((string) $file['name']) . '">' . $this->escape((string) $file['name']) . '</a> <span class="helper-text">(' . (int) $file['size'] . ' bytes)</span></div>';
            }

            $rows .= '<tr><td><code>' . $this->escape((string) $backup['name']) . '</code></td><td>' . $filesHtml . '</td></tr>';
        }

        return $this->templates->render('pages/backups-index.html', [
            'create_panel_html' => $this->panel(
                '',
                '<p>Create a fallback backup snapshot with database exports and uploads copy.</p>'
                . $this->actionForm('/admin/backups/create', 'Create backup', $csrfInputName, $csrfToken, '', 'inline-form')
            ),
            'table_panel_html' => $this->panel('', $this->table(
                ['Backup', 'Files'],
                $rows,
                'No backups created yet.',
                2
            )),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $mediaItems
     */
    public function renderMediaPage(array $mediaItems, string $csrfInputName, string $csrfToken): string
    {
        $rows = '';

        foreach ($mediaItems as $item) {
            $alt = (string) ($item['alt'] ?? '');
            $markdownSnippet = '![' . str_replace([']', '['], '', $alt) . '](' . (string) $item['url'] . ')';
            $htmlSnippet = '<img src="' . (string) $item['url'] . '" alt="' . str_replace('"', '&quot;', $alt) . '">';
            $snippetHtml = '<div class="snippet-stack">'
                . '<label class="snippet-label" for="media-markdown-' . (int) $item['id'] . '">Markdown</label>'
                . '<div class="snippet-row"><input id="media-markdown-' . (int) $item['id'] . '" class="snippet-input" value="' . $this->escape($markdownSnippet) . '" readonly><button type="button" class="button-secondary snippet-button" data-copy-text="' . $this->escape($markdownSnippet) . '">Copy</button></div>'
                . '<label class="snippet-label" for="media-html-' . (int) $item['id'] . '">HTML</label>'
                . '<div class="snippet-row"><input id="media-html-' . (int) $item['id'] . '" class="snippet-input" value="' . $this->escape($htmlSnippet) . '" readonly><button type="button" class="button-secondary snippet-button" data-copy-text="' . $this->escape($htmlSnippet) . '">Copy</button></div>'
                . '</div>';

            $altForm = '<form method="post" action="/admin/media/' . (int) $item['id'] . '" class="inline-form">'
                . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
                . '<input name="alt" value="' . $this->escape($alt) . '" placeholder="Alt text">'
                . '<button type="submit" class="button-secondary">Save alt</button></form>';

            $rows .= '<tr>'
                . '<td><img src="' . $this->escape((string) $item['url']) . '" alt="" style="max-width:96px;border-radius:10px;"></td>'
                . '<td><strong>' . $this->escape((string) $item['original_filename']) . '</strong><br><span class="helper-text">' . $this->escape((string) $item['mime_type']) . '</span></td>'
                . '<td>' . $this->escape((string) $item['size_bytes']) . '</td>'
                . '<td>' . $altForm . '</td>'
                . '<td>' . $snippetHtml . '</td>'
                . '<td>' . $this->actionForm('/admin/media/' . (int) $item['id'] . '/delete', 'Delete', $csrfInputName, $csrfToken, 'button-secondary', 'inline-form', 'Delete this media item?') . '</td>'
                . '</tr>';
        }

        $uploadBody = '<form method="post" action="/admin/media/upload" enctype="multipart/form-data" class="settings-form">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . $this->inputRow('media_file', 'Image file', 'file', '', '', '', 'accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp"')
            . $this->inputRow('alt', 'Alt text', 'text', '', 'Optional descriptive alt text')
            . $this->submitRow('<button type="submit">Upload image</button>')
            . '</form>';

        return $this->templates->render('pages/media-index.html', [
            'upload_panel_html' => $this->panel('Upload image', $uploadBody),
            'library_panel_html' => $this->panel(
                'Library',
                '<p class="helper-text">Use the copy buttons to paste a Markdown or HTML image snippet into a page or post body.</p>'
                . $this->table(['Preview', 'File', 'Bytes', 'Alt text', 'Insert snippet', 'Actions'], $rows, 'No media uploaded yet.', 6)
            ),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $menus
     */
    public function renderMenusIndexPage(array $menus, string $csrfInputName, string $csrfToken): string
    {
        $rows = '';

        foreach ($menus as $menu) {
            $rows .= '<tr><td><strong>' . $this->escape((string) $menu['name']) . '</strong></td>'
                . '<td><code>' . $this->escape((string) $menu['key']) . '</code></td>'
                . '<td><a href="/admin/menus/' . (int) $menu['id'] . '/edit">Edit</a></td></tr>';
        }

        $createBody = '<form method="post" action="/admin/menus" class="settings-form">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . $this->inputRow('name', 'Name', 'text', '', '')
            . $this->inputRow('key', 'Key', 'text', '', '')
            . $this->submitRow('<button type="submit">Create menu</button>')
            . '</form>';

        return $this->templates->render('pages/menus-index.html', [
            'create_panel_html' => $this->panel('Create menu', $createBody),
            'table_panel_html' => $this->panel('Menus', $this->table(['Name', 'Key', 'Actions'], $rows, 'No menus created yet.', 3)),
        ]);
    }

    /**
     * @param array<string, mixed> $menu
     * @param array<int, array<string, mixed>> $pages
     */
    public function renderMenuEditorPage(array $menu, array $pages, string $csrfInputName, string $csrfToken): string
    {
        $items = $menu['items'] ?? [];
        $rows = '';
        $count = max(5, count($items));

        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index] ?? [
                'label' => '',
                'page_id' => null,
                'url' => '',
                'target' => '_self',
                'sort_order' => $index + 1,
                'parent_id' => null,
            ];

            $pageOptions = '<option value="">Custom URL</option>';

            foreach ($pages as $page) {
                $pageOptions .= '<option value="' . $this->escape((string) $page['id']) . '"' . ((string) ($item['page_id'] ?? '') === (string) $page['id'] ? ' selected' : '') . '>' . $this->escape((string) $page['path']) . '</option>';
            }

            $parentOptions = '<option value="">No parent</option>';

            foreach ($items as $parentIndex => $parentItem) {
                $parentOptions .= '<option value="' . ($parentIndex + 1) . '"' . ((string) ($item['parent_id'] ?? '') === (string) $parentItem['id'] ? ' selected' : '') . '>Row ' . ($parentIndex + 1) . ' · ' . $this->escape((string) $parentItem['label']) . '</option>';
            }

            $rows .= '<tr>'
                . '<td>' . ($index + 1) . '</td>'
                . '<td><input name="item_label[]" value="' . $this->escape((string) ($item['label'] ?? '')) . '"></td>'
                . '<td><select name="item_page_id[]">' . $pageOptions . '</select></td>'
                . '<td><input name="item_url[]" value="' . $this->escape((string) ($item['url'] ?? '')) . '" placeholder="/services"></td>'
                . '<td><select name="item_parent_index[]">' . $parentOptions . '</select></td>'
                . '<td><input type="number" name="item_sort_order[]" value="' . $this->escape((string) ($item['sort_order'] ?? ($index + 1))) . '"></td>'
                . '<td><select name="item_target[]"><option value="_self"' . (($item['target'] ?? '_self') === '_self' ? ' selected' : '') . '>Same tab</option><option value="_blank"' . (($item['target'] ?? '_self') === '_blank' ? ' selected' : '') . '>New tab</option></select></td>'
                . '</tr>';
        }

        $body = '<form method="post" action="/admin/menus/' . (int) $menu['id'] . '" class="settings-form">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . $this->inputRow('name', 'Menu name', 'text', (string) $menu['name'], '')
            . '<p class="helper-text">Each row can link to a published page or a custom URL. Parent rows create nested navigation.</p>'
            . $this->table(['#', 'Label', 'Page', 'Custom URL', 'Parent', 'Sort', 'Target'], $rows, 'No menu items yet.', 7)
            . $this->submitRow('<button type="submit">Save menu</button>')
            . '</form>';

        return $this->templates->render('pages/menu-editor.html', [
            'editor_panel_html' => $this->panel('Menu details', $body),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $forms
     */
    public function renderFormsIndexPage(array $forms): string
    {
        $rows = '';

        foreach ($forms as $form) {
            $rows .= '<tr><td><strong>' . $this->escape((string) $form['name']) . '</strong></td>'
                . '<td><code>' . $this->escape((string) $form['key']) . '</code></td>'
                . '<td>' . $this->badge((bool) $form['is_active'] ? 'Active' : 'Inactive') . '</td>'
                . '<td><a href="/admin/forms/' . (int) $form['id'] . '/edit">Edit</a> · <a href="/admin/forms/' . (int) $form['id'] . '/submissions">Submissions</a></td></tr>';
        }

        return $this->templates->render('pages/forms-index.html', [
            'forms_panel_html' => $this->panel(
                '',
                '<div class="panel-actions"><a class="button-secondary" href="/admin/forms/create">Create form</a></div>'
                . $this->table(['Name', 'Key', 'Status', 'Actions'], $rows, 'No forms yet.', 4)
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function renderFormEditorPage(array $values, ?int $id, string $csrfInputName, string $csrfToken): string
    {
        $fieldsHtml = '';
        $rows = $values['fields'] ?? [];
        $count = max(5, count($rows));

        for ($index = 0; $index < $count; $index++) {
            $field = $rows[$index] ?? ['type' => 'text', 'name' => '', 'label' => '', 'placeholder' => '', 'is_required' => false, 'sort_order' => $index + 1];
            $type = (string) $field['type'];
            $fieldsHtml .= '<tr><td><select name="field_type[]"><option value="text"' . ($type === 'text' ? ' selected' : '') . '>Text</option><option value="email"' . ($type === 'email' ? ' selected' : '') . '>Email</option><option value="textarea"' . ($type === 'textarea' ? ' selected' : '') . '>Textarea</option></select></td>'
                . '<td><input name="field_name[]" value="' . $this->escape((string) $field['name']) . '"></td>'
                . '<td><input name="field_label[]" value="' . $this->escape((string) $field['label']) . '"></td>'
                . '<td><input name="field_placeholder[]" value="' . $this->escape((string) ($field['placeholder'] ?? '')) . '"></td>'
                . '<td><input type="hidden" name="field_required[' . $index . ']" value="0"><input type="checkbox" name="field_required[' . $index . ']" value="1"' . ((bool) ($field['is_required'] ?? false) ? ' checked' : '') . '></td>'
                . '<td><input type="number" name="field_sort_order[]" value="' . $this->escape((string) ($field['sort_order'] ?? ($index + 1))) . '"></td></tr>';
        }

        $action = $id === null ? '/admin/forms' : '/admin/forms/' . $id;
        $details = $this->inputRow('name', 'Name', 'text', (string) $values['name'], '')
            . $this->inputRow('key', 'Key', 'text', (string) $values['key'], '')
            . $this->textareaRow('description', 'Description', (string) ($values['description'] ?? ''), '')
            . $this->textareaRow('success_message', 'Success message', (string) ($values['success_message'] ?? ''), '');
        $behavior = $this->checkboxRow('send_email', 'Send email', (bool) $values['send_email'], '')
            . $this->inputRow('email_to', 'Email to', 'text', (string) ($values['email_to'] ?? ''), '')
            . $this->checkboxRow('save_submissions', 'Save submissions', (bool) $values['save_submissions'], '')
            . $this->checkboxRow('captcha_enabled', 'Captcha placeholder', (bool) $values['captcha_enabled'], '')
            . $this->checkboxRow('is_active', 'Active', (bool) $values['is_active'], '');
        $body = '<form method="post" action="' . $this->escape($action) . '" class="settings-form">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . '<div class="settings-grid"><section class="settings-section"><h3>Details</h3>' . $details . '</section><section class="settings-section"><h3>Behavior</h3>' . $behavior . '</section></div>'
            . $this->panel('Fields', $this->table(['Type', 'Name', 'Label', 'Placeholder', 'Required', 'Sort'], $fieldsHtml, 'No form fields yet.', 6))
            . $this->submitRow('<button type="submit">Save form</button>')
            . '</form>';

        return $this->templates->render('pages/form-editor.html', [
            'editor_panel_html' => $this->panel('', $body),
        ]);
    }

    /**
     * @param array<string, mixed> $form
     * @param array<int, array<string, mixed>> $submissions
     */
    public function renderFormSubmissionsPage(array $form, array $submissions): string
    {
        $rows = '';

        foreach ($submissions as $submission) {
            $payloadRows = '';

            foreach ((array) ($submission['payload'] ?? []) as $name => $value) {
                $payloadRows .= '<div><strong>' . $this->escape((string) $name) . ':</strong> ' . $this->escape((string) $value) . '</div>';
            }

            $rows .= '<tr><td>' . (int) $submission['id'] . '</td><td>' . $this->escape((string) ($submission['created_at'] ?? '')) . '</td><td>' . $payloadRows . '</td></tr>';
        }

        return $this->templates->render('pages/form-submissions.html', [
            'submissions_panel_html' => $this->panel(
                '',
                '<p><a class="panel-link" href="/admin/forms/' . (int) $form['id'] . '/edit">Back to form</a></p>'
                . $this->table(['ID', 'Created', 'Payload'], $rows, 'No submissions yet.', 3)
            ),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     */
    public function renderPagesIndexPage(array $pages, string $csrfInputName, string $csrfToken, callable $previewTokenForPage): string
    {
        $rows = '';

        foreach ($pages as $page) {
            $previewUrl = '/preview/' . rawurlencode((string) $previewTokenForPage((int) $page['id']));
            $actions = '<a href="/admin/pages/' . (int) $page['id'] . '/edit">Edit</a> · <a href="' . $this->escape($previewUrl) . '" target="_blank">Preview</a>'
                . $this->actionForm('/admin/pages/' . (int) $page['id'] . '/publish', 'Publish', $csrfInputName, $csrfToken)
                . $this->actionForm('/admin/pages/' . (int) $page['id'] . '/archive', 'Archive', $csrfInputName, $csrfToken)
                . $this->actionForm('/admin/pages/' . (int) $page['id'] . '/delete', 'Delete', $csrfInputName, $csrfToken, 'link-button danger-link', 'inline-form action-form', 'Delete this page?');
            $rows .= '<tr><td>' . $this->escape((string) $page['title']) . '</td><td><code>' . $this->escape((string) $page['path']) . '</code></td><td>' . $this->escape((string) $page['type']) . '</td><td>' . $this->badge((string) $page['status']) . '</td><td>' . $this->escape((string) ($page['updated_at'] ?? '')) . '</td><td>' . $actions . '</td></tr>';
        }

        return $this->templates->render('pages/pages-index.html', [
            'pages_panel_html' => $this->panel(
                '',
                '<div class="panel-actions"><a class="button-secondary" href="/admin/pages/create">Create page</a></div>'
                . $this->table(['Title', 'Path', 'Type', 'Status', 'Updated', 'Actions'], $rows, 'No pages yet. Create the first one to start the site tree.', 6)
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<int, string>> $errors
     * @param array<int, array<string, mixed>> $parents
     */
    public function renderPageEditorPage(array $values, array $errors, string $action, ?int $pageId, string $previewLink, array $parents, string $csrfInputName, string $csrfToken): string
    {
        $parentOptions = '<option value="">No parent</option>';

        foreach ($parents as $parent) {
            $parentOptions .= '<option value="' . $this->escape((string) $parent['id']) . '"' . ((string) $values['parent_id'] === (string) $parent['id'] ? ' selected' : '') . '>' . $this->escape((string) $parent['path']) . '</option>';
        }

        $previewPanel = $previewLink !== '' ? '<p><a class="panel-link" href="' . $this->escape($previewLink) . '" target="_blank">Open preview</a></p>' : '';
        $statusActions = '';

        if ($pageId !== null) {
            $statusActions = '<div class="submit-row action-row">'
                . $this->actionForm('/admin/pages/' . $pageId . '/publish', 'Publish', $csrfInputName, $csrfToken, 'button-secondary', 'inline-form')
                . $this->actionForm('/admin/pages/' . $pageId . '/archive', 'Archive', $csrfInputName, $csrfToken, 'button-secondary', 'inline-form')
                . $this->actionForm('/admin/pages/' . $pageId . '/delete', 'Delete', $csrfInputName, $csrfToken, 'button-secondary', 'inline-form', 'Delete this page?')
                . '</div>';
        }

        $routing = $this->selectRow('parent_id', 'Parent page', $parentOptions, 'Choosing a parent locks the public URL to /parent/slug.', $errors['parent_id'][0] ?? '')
            . $this->inputRow('slug', 'Slug', 'text', (string) $values['slug'], 'Leave blank to auto-generate from the title.', $errors['slug'][0] ?? '')
            . $this->inputRow('path', 'Path', 'text', (string) $values['path'], 'Top-level pages can set their own path. Child pages always inherit the parent path.', $errors['path'][0] ?? '')
            . $this->selectRow('status', 'Status', $this->optionsHtml([
                ['value' => 'draft', 'label' => 'Draft', 'selected' => $values['status'] === 'draft'],
                ['value' => 'published', 'label' => 'Published', 'selected' => $values['status'] === 'published'],
                ['value' => 'archived', 'label' => 'Archived', 'selected' => $values['status'] === 'archived'],
            ]), 'Preview can show unpublished content with a signed token.', $errors['status'][0] ?? '');
        $content = $this->selectRow('content_mode', 'Mode', $this->optionsHtml([
            ['value' => 'markdown', 'label' => 'Markdown', 'selected' => $values['content_mode'] === 'markdown'],
            ['value' => 'html', 'label' => 'HTML', 'selected' => $values['content_mode'] === 'html'],
        ]), 'Markdown is converted to safe HTML. Raw HTML is sanitized before storage.', $errors['content_mode'][0] ?? '')
            . $this->textareaRow('excerpt', 'Excerpt', (string) $values['excerpt'], 'Optional summary for future listing and SEO use.', $errors['excerpt'][0] ?? '')
            . $this->textareaRow('content_raw', 'Body', (string) $values['content_raw'], 'Main content source that feeds the renderer pipeline. Upload images in Media, then paste the copied Markdown or HTML snippet here.', $errors['content_raw'][0] ?? '')
            . $previewPanel;
        $templateComments = $this->inputRow('template', 'Template', 'text', (string) $values['template'], 'Optional template override for the public renderer.', $errors['template'][0] ?? '')
            . $this->selectRow('comments_enabled', 'Comments override', $this->optionsHtml([
                ['value' => '', 'label' => 'Inherit site default', 'selected' => $values['comments_enabled'] === ''],
                ['value' => '1', 'label' => 'Enabled', 'selected' => $values['comments_enabled'] === '1'],
                ['value' => '0', 'label' => 'Disabled', 'selected' => $values['comments_enabled'] === '0'],
            ]), 'Use page-specific comment behavior when needed.', $errors['comments_enabled'][0] ?? '');
        $seo = $this->inputRow('seo_title', 'SEO title', 'text', (string) $values['seo_title'], 'Optional SEO title override.', $errors['seo_title'][0] ?? '')
            . $this->textareaRow('seo_description', 'SEO description', (string) $values['seo_description'], 'Optional SEO description.', $errors['seo_description'][0] ?? '')
            . $this->inputRow('seo_keywords', 'SEO keywords', 'text', (string) $values['seo_keywords'], 'Optional comma-separated keywords.', $errors['seo_keywords'][0] ?? '');

        $formHtml = '<form method="post" action="' . $this->escape($action) . '" class="settings-form" data-autosave-key="' . $this->escape($this->autosaveKey($pageId)) . '">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . $this->inputRow('title', 'Title', 'text', (string) $values['title'], 'Required. Used to generate a slug when the slug is left blank.', $errors['title'][0] ?? '')
            . '<div class="settings-grid"><section class="settings-section"><h3>Routing</h3>' . $routing . '</section><section class="settings-section"><h3>Content</h3>' . $content . '</section><section class="settings-section"><h3>Template and comments</h3>' . $templateComments . '</section><section class="settings-section"><h3>SEO</h3>' . $seo . '</section></div>'
            . $this->submitRow('<button type="submit">Save page</button>')
            . '<p class="helper-text autosave-status" data-autosave-status>Drafts save automatically in this browser.</p>'
            . '</form>';

        return $this->templates->render('pages/page-editor.html', [
            'editor_panel_html' => $this->panel('', $formHtml . $statusActions),
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, array{key: string, name: string}> $websiteThemes
     * @param array<int, array{key: string, name: string}> $dashboardThemes
     * @param array<string, array<int, string>> $errors
     */
    public function renderSettingsPage(array $values, array $websiteThemes, array $dashboardThemes, array $errors, string $csrfInputName, string $csrfToken): string
    {
        $websiteThemeCards = '';

        foreach ($websiteThemes as $theme) {
            $meta = $this->themeMeta($theme['key'], $theme['name'], 'website');
            $websiteThemeCards .= '<label class="theme-card"><input class="theme-card-input" type="radio" name="theme_active" value="' . $this->escape($theme['key']) . '"' . ($values['theme_active'] === $theme['key'] ? ' checked' : '') . '><span class="theme-card-preview theme-preview-' . $this->escape($meta['preview']) . '" aria-hidden="true"><span></span><span></span><span></span></span><span class="theme-card-body"><strong>' . $this->escape($theme['name']) . '</strong><span>' . $this->escape($meta['description']) . '</span></span></label>';
        }

        $dashboardThemeCards = '';

        foreach ($dashboardThemes as $theme) {
            $meta = $this->themeMeta($theme['key'], $theme['name'], 'dashboard');
            $dashboardThemeCards .= '<label class="theme-card"><input class="theme-card-input" type="radio" name="dashboard_theme_active" value="' . $this->escape($theme['key']) . '"' . ($values['dashboard_theme_active'] === $theme['key'] ? ' checked' : '') . '><span class="theme-card-preview theme-preview-' . $this->escape($meta['preview']) . '" aria-hidden="true"><span></span><span></span><span></span></span><span class="theme-card-body"><strong>' . $this->escape($theme['name']) . '</strong><span>' . $this->escape($meta['description']) . '</span></span></label>';
        }

        $saveBody = '<form method="post" action="/admin/settings" class="settings-form">'
            . '<input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '">'
            . '<input type="hidden" name="action" value="save">'
            . $this->inputRow('site_title', 'Site title', 'text', (string) $values['site_title'], 'Used in public templates as {{ site.title }}.', $errors['site_title'][0] ?? '')
            . $this->textareaRow('site_description', 'Site description', (string) $values['site_description'], 'Used in public templates as {{ site.description }}.', $errors['site_description'][0] ?? '')
            . '<section class="settings-section" id="dashboard-themes"><h3>Dashboard theme</h3><p class="helper-text">Choose the theme used by the admin dashboard surface.</p><div class="theme-card-grid theme-card-grid-dashboard">' . $dashboardThemeCards . '</div>' . $this->fieldError($errors['dashboard_theme_active'][0] ?? '') . '</section>'
            . '<section class="settings-section" id="website-themes"><h3>Website theme</h3><p class="helper-text">Choose the public-facing style pack. Cyberpunk ships in both light and dark variants, plus anime-pastel and cartoon-inspired options.</p><div class="theme-card-grid">' . $websiteThemeCards . '</div>' . $this->fieldError($errors['theme_active'][0] ?? '') . '</section>'
            . '<div class="settings-grid"><section class="settings-section"><h3>Comments</h3>'
            . $this->checkboxRow('comments_enabled', 'Enable comments by default', (bool) $values['comments_enabled'], 'New public content can inherit this setting.', $errors['comments_enabled'][0] ?? '')
            . $this->checkboxRow('comments_moderation_required', 'Require moderation', (bool) $values['comments_moderation_required'], 'Keep public comments queued until approved.', $errors['comments_moderation_required'][0] ?? '')
            . '</section><section class="settings-section"><h3>Uploads</h3>'
            . $this->inputRow('uploads_url', 'Uploads URL', 'text', (string) $values['uploads_url'], 'Public base path for uploaded files.', $errors['uploads_url'][0] ?? '')
            . $this->inputRow('uploads_max_size_mb', 'Max size (MB)', 'number', (string) $values['uploads_max_size_mb'], 'Validation baseline for upload flows.', $errors['uploads_max_size_mb'][0] ?? '')
            . '</section><section class="settings-section"><h3>Cache</h3>'
            . $this->selectRow('cache_driver', 'Cache driver', $this->optionsHtml([
                ['value' => 'file', 'label' => 'File cache', 'selected' => $values['cache_driver'] === 'file'],
                ['value' => 'null', 'label' => 'Null cache', 'selected' => $values['cache_driver'] === 'null'],
            ]), 'Choose whether the app caches rendered and settings data.', $errors['cache_driver'][0] ?? '')
            . '<div class="admin-theme-tip"><p><strong>Admin theme:</strong> use the light/dark toggle in the top bar or sidebar. That preference stays in this browser.</p></div>'
            . $this->submitRow('<button type="submit">Save settings</button>')
            . '</section></div></form>';

        $maintenanceBody = '<p>Clear cached settings and public fragments after environment or content changes.</p>'
            . '<form method="post" action="/admin/settings" class="inline-form"><input type="hidden" name="' . $this->escape($csrfInputName) . '" value="' . $this->escape($csrfToken) . '"><input type="hidden" name="action" value="clear_cache"><button type="submit" class="button-secondary">Clear cache</button></form>';

        return $this->templates->render('pages/settings-index.html', [
            'settings_panel_html' => $this->panel('Site identity', $saveBody),
            'maintenance_panel_html' => $this->panel('Maintenance', $maintenanceBody),
        ]);
    }

    /**
     * @param array{title: string, message: string, next: string} $labels
     */
    public function renderPlaceholderPage(array $labels): string
    {
        return $this->templates->render('pages/placeholder.html', [
            'placeholder_panel_html' => $this->panel(
                $labels['title'],
                '<p>' . $this->escape($labels['message']) . '</p><p class="helper-text">' . $this->escape($labels['next']) . '</p>'
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->templates->render($template, $data);
    }

    public function panel(string $title, string $body, string $actionsHtml = '', string $className = 'panel'): string
    {
        return $this->templates->render('components/panel.html', [
            'title_html' => $title !== '' ? '<h2>' . $this->escape($title) . '</h2>' : '',
            'body' => $body,
            'actions_html' => $actionsHtml,
            'panel_class' => $className,
        ]);
    }

    /**
     * @param array<int, string> $headers
     */
    public function table(array $headers, string $rowsHtml, string $emptyMessage, int $colspan): string
    {
        $headerHtml = '';

        foreach ($headers as $header) {
            $headerHtml .= '<th>' . $this->escape($header) . '</th>';
        }

        return $this->templates->render('components/table.html', [
            'header_html' => $headerHtml,
            'rows_html' => $rowsHtml !== '' ? $rowsHtml : '<tr><td colspan="' . $colspan . '">' . $this->escape($emptyMessage) . '</td></tr>',
        ]);
    }

    public function actionForm(string $action, string $label, string $csrfInputName, string $csrfToken, string $buttonClass = 'link-button', string $formClass = 'inline-form action-form', ?string $confirm = null): string
    {
        return $this->templates->render('components/action-form.html', [
            'action' => $action,
            'csrf_name' => $csrfInputName,
            'csrf_token' => $csrfToken,
            'label' => $label,
            'button_class' => $buttonClass,
            'form_class' => $formClass,
            'confirm_attr_html' => $confirm !== null ? ' data-confirm="' . $this->escape($confirm) . '"' : '',
        ]);
    }

    public function inputRow(string $name, string $label, string $type, string $value, string $helper = '', string $error = '', string $extraAttributes = ''): string
    {
        return $this->templates->render('components/form/input-row.html', [
            'name' => $name,
            'label' => $label,
            'type' => $type,
            'value' => $value,
            'helper' => $helper,
            'error_html' => $this->fieldError($error),
            'extra_attributes_html' => $extraAttributes !== '' ? ' ' . $extraAttributes : '',
        ]);
    }

    public function textareaRow(string $name, string $label, string $value, string $helper = '', string $error = ''): string
    {
        return $this->templates->render('components/form/textarea-row.html', [
            'name' => $name,
            'label' => $label,
            'value' => $value,
            'helper' => $helper,
            'error_html' => $this->fieldError($error),
        ]);
    }

    public function selectRow(string $name, string $label, string $optionsHtml, string $helper = '', string $error = ''): string
    {
        return $this->templates->render('components/form/select-row.html', [
            'name' => $name,
            'label' => $label,
            'options_html' => $optionsHtml,
            'helper' => $helper,
            'error_html' => $this->fieldError($error),
        ]);
    }

    public function checkboxRow(string $name, string $label, bool $checked, string $helper = '', string $error = ''): string
    {
        return $this->templates->render('components/form/checkbox-row.html', [
            'name' => $name,
            'label' => $label,
            'checked_attr_html' => $checked ? ' checked' : '',
            'helper' => $helper,
            'error_html' => $this->fieldError($error),
        ]);
    }

    public function submitRow(string $buttonsHtml): string
    {
        return $this->templates->render('components/form/submit-row.html', [
            'buttons_html' => $buttonsHtml,
        ]);
    }

    public function badge(string $label): string
    {
        $tone = match (strtolower($label)) {
            'published', 'active', 'approved' => 'badge-live',
            'draft', 'inactive', 'pending' => 'badge-draft',
            'spam', 'archived', 'deleted' => 'badge-outline',
            default => 'badge-outline',
        };

        return '<span class="badge ' . $tone . '">' . $this->escape($label) . '</span>';
    }

    /**
     * @param array<int, array{value: string, label: string, selected: bool}> $options
     */
    private function optionsHtml(array $options): string
    {
        $html = '';

        foreach ($options as $option) {
            $html .= '<option value="' . $this->escape($option['value']) . '"' . ($option['selected'] ? ' selected' : '') . '>' . $this->escape($option['label']) . '</option>';
        }

        return $html;
    }

    private function fieldError(string $error): string
    {
        return $error !== '' ? '<p class="field-error">' . $this->escape($error) . '</p>' : '';
    }

    private function autosaveKey(?int $pageId): string
    {
        return $pageId === null ? 'autosave:page:create' : 'autosave:page:edit:' . $pageId;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $analytics
     */
    private function trafficMonitorCard(array $analytics): string
    {
        $configs = [
            '24h' => $this->hourlyChartConfig($analytics['hourly'] ?? []),
            '3d' => $this->dailyChartConfig($analytics['daily_3d'] ?? []),
            '7d' => $this->dailyChartConfig($analytics['daily_7d'] ?? []),
        ];

        return '<section class="traffic-monitor-card"><div class="traffic-monitor-head"><div><p class="page-kicker">Signal</p><h3>Traffic monitor</h3></div><div class="traffic-range-switch" role="tablist" aria-label="Traffic range">'
            . $this->trafficRangeButton('24h', true)
            . $this->trafficRangeButton('3d', false)
            . $this->trafficRangeButton('7d', false)
            . '</div></div><canvas class="admin-chart-canvas traffic-monitor-canvas" width="640" height="260" data-chart-type="line" data-chart-configs="' . $this->escape($this->json($configs)) . '" data-chart-default="24h"></canvas>'
            . $this->trafficLeaderboard($analytics['top_week_content'] ?? [])
            . '</section>';
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
            $date = (string) ($row['stat_date'] ?? '');
            $labels[] = $date !== '' ? substr($date, 5) : '';
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
        $unique = [];

        foreach ($rows as $row) {
            $labels[] = substr((string) ($row['label'] ?? ''), 11, 5);
            $views[] = (int) ($row['views'] ?? 0);
            $unique[] = (int) ($row['unique_visitors'] ?? 0);
        }

        return [
            'labels' => $labels,
            'series' => [
                ['label' => 'Views', 'values' => $views, 'color' => '#1df7ff'],
                ['label' => 'Unique', 'values' => $unique, 'color' => '#ff2bd6'],
            ],
        ];
    }

    private function chartCard(string $title, string $type, array $config): string
    {
        return $this->templates->render('components/chart-card.html', [
            'title' => $title,
            'chart_type' => $type,
            'chart_config' => $this->json($config),
        ]);
    }

    private function signalStat(string $label, string $value, ?int $delta): string
    {
        $deltaHtml = $delta === null ? '' : '<span class="signal-delta ' . ($delta >= 0 ? 'is-up' : 'is-down') . '">' . ($delta >= 0 ? '+' : '') . $delta . '</span>';

        return '<article class="signal-stat"><p>' . $this->escape($label) . '</p><strong>' . $this->escape($value) . '</strong>' . $deltaHtml . '</article>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function recentEventsCard(array $rows): string
    {
        $items = '';

        foreach (array_slice($rows, 0, 3) as $row) {
            $items .= '<article class="overview-event-item"><div class="overview-event-icon">' . $this->escape(strtoupper(substr((string) ($row['device_type'] ?? 'na'), 0, 2))) . '</div><div class="overview-event-copy"><strong>' . $this->escape((string) ($row['title'] ?? 'Untitled')) . '</strong><span>' . $this->escape((string) ($row['path'] ?? '/')) . ' • ' . $this->escape((string) ($row['referer_host'] ?? 'Direct')) . '</span></div><time>' . $this->escape(substr((string) ($row['created_at'] ?? ''), 11, 5)) . '</time></article>';
        }

        if ($items === '') {
            $items = '<p class="overview-empty-state">No recent traffic events yet. The signal feed will light up as visitors arrive.</p>';
        }

        return '<section class="overview-events-card"><div class="chart-card-head"><p class="page-kicker">Recent activity</p></div><div class="overview-event-list">' . $items . '</div></section>';
    }

    private function quickLinkCard(string $href, string $icon, string $title, string $copy, string $action): string
    {
        return '<a class="quick-link-card" href="' . $this->escape($href) . '"><span class="quick-link-icon">' . $this->escape($icon) . '</span><div class="quick-link-copy"><strong>' . $this->escape($title) . '</strong><span>' . $this->escape($copy) . '</span></div><b class="quick-link-action">' . $this->escape($action) . '</b></a>';
    }

    private function trafficRangeButton(string $label, bool $active): string
    {
        return '<button type="button" class="traffic-range-button' . ($active ? ' is-active' : '') . '" data-chart-range="' . $this->escape($label) . '" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '">' . $this->escape($label) . '</button>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function trafficLeaderboard(array $rows): string
    {
        $items = '';

        foreach (array_slice($rows, 0, 3) as $row) {
            $items .= '<article class="traffic-leader-item"><a class="traffic-leader-link" href="' . $this->escape((string) ($row['path'] ?? '/')) . '">' . $this->escape($this->shortLabel((string) ($row['title'] ?? 'Untitled'), 22)) . '</a><div class="traffic-leader-metric"><span class="traffic-leader-value">' . (int) ($row['views'] ?? 0) . '</span><i class="fa-solid fa-chart-line" aria-hidden="true"></i></div></article>';
        }

        if ($items === '') {
            $items = '<p class="overview-empty-state">No traffic leaders yet. Top pages will appear once visits start landing.</p>';
        }

        return '<section class="traffic-leaderboard"><div class="chart-card-head"><p class="page-kicker">Leaderboard</p><h3>Top pages, 7d</h3></div><div class="traffic-leaderboard-list">' . $items . '</div></section>';
    }

    private function shortLabel(string $value, int $limit): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, $limit - 1)) . '...';
    }

    /**
     * @return array{description: string, preview: string}
     */
    private function themeMeta(string $key, string $fallbackName, string $surface = 'website'): array
    {
        if ($surface === 'dashboard' && $key === 'default') {
            return ['description' => 'Default dashboard workspace theme for the admin surface.', 'preview' => 'default'];
        }

        return match ($key) {
            'default' => ['description' => 'Soft editorial default with clean typography and balanced spacing.', 'preview' => 'default'],
            'cyberpunk-light' => ['description' => 'Bright neon grids, chrome cards, and high-energy hover effects.', 'preview' => 'cyberpunk-light'],
            'cyberpunk-dark' => ['description' => 'Night-city glow, holographic panels, and deep contrast.', 'preview' => 'cyberpunk-dark'],
            'pastel-anime' => ['description' => 'Dreamy gradients, manga-card framing, and soft candy tones.', 'preview' => 'pastel-anime'],
            'cartoon-pop' => ['description' => 'Bold outlines, playful shapes, and Saturday-morning energy.', 'preview' => 'cartoon-pop'],
            default => ['description' => $fallbackName . ' theme for the public site.', 'preview' => 'default'],
        };
    }

    private function json(array $value): string
    {
        try {
            return (string) json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }
}
