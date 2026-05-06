<?php

declare(strict_types=1);

namespace PlainCMS\Core;

use PlainCMS\Core\Admin\AdminNavigation;
use PlainCMS\Core\Cache\CacheInterface;

final class SettingsService
{
    private const SETTINGS_CACHE_KEY = 'settings:all';
    private const SITE_CONTEXT_CACHE_KEY = 'settings:site_context';
    private const THEMES_CACHE_KEY = 'theme:available';

    public function __construct(
        private readonly Database $database,
        private readonly CacheInterface $cache,
        private readonly string $themesPath,
        private readonly array $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $cached = $this->cache->get(self::SETTINGS_CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        $rows = $this->database->select('SELECT key, value, group_name FROM settings ORDER BY key ASC');
        $settings = [];

        foreach ($rows as $row) {
            $decoded = is_array($row['value']) ? $row['value'] : json_decode((string) $row['value'], true);
            $settings[(string) $row['key']] = is_array($decoded) && array_key_exists('value', $decoded)
                ? $decoded['value']
                : $decoded;
        }

        $merged = $this->defaults();

        foreach ($settings as $key => $value) {
            $merged[$key] = $value;
        }

        $this->cache->put(self::SETTINGS_CACHE_KEY, $merged, 3600);

        return $merged;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->all();

        return $settings[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(): array
    {
        $settings = $this->all();

        return [
            'site_title' => (string) ($settings['site.title'] ?? 'MidexCMS Site'),
            'site_description' => (string) ($settings['site.description'] ?? 'Simple website powered by MidexCMS'),
            'dashboard_theme_active' => (string) ($settings['dashboard.theme.active'] ?? 'default'),
            'theme_active' => (string) ($settings['theme.active'] ?? 'default'),
            'comments_enabled' => (bool) ($settings['comments.enabled'] ?? true),
            'comments_moderation_required' => (bool) ($settings['comments.moderation_required'] ?? true),
            'uploads_url' => (string) ($settings['uploads.url'] ?? ((string) ($this->config['uploads']['url'] ?? '/uploads'))),
            'uploads_max_size_mb' => (int) ($settings['uploads.max_size_mb'] ?? ((int) ($this->config['uploads']['max_size_mb'] ?? 10))),
            'cache_driver' => (string) ($settings['cache.driver'] ?? ((string) ($this->config['cache']['driver'] ?? 'file'))),
        ];
    }

    public function siteContext(): SiteContext
    {
        $cached = $this->cache->get(self::SITE_CONTEXT_CACHE_KEY);

        if (is_array($cached)) {
            return new SiteContext(
                (string) $cached['site']['title'],
                (string) $cached['site']['description'],
                (string) $cached['theme']['active'],
                (bool) $cached['comments']['enabled'],
                (bool) $cached['comments']['moderation_required'],
                (string) $cached['uploads']['url'],
                (int) $cached['uploads']['max_size_mb'],
                (string) $cached['cache']['driver'],
            );
        }

        $settings = $this->formData();
        $context = new SiteContext(
            $settings['site_title'],
            $settings['site_description'],
            $settings['theme_active'],
            $settings['comments_enabled'],
            $settings['comments_moderation_required'],
            $settings['uploads_url'],
            $settings['uploads_max_size_mb'],
            $settings['cache_driver'],
        );

        $this->cache->put(self::SITE_CONTEXT_CACHE_KEY, $context->toArray(), 3600);

        return $context;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(array $values): void
    {
        $map = [
            'site.title' => ['group' => 'site', 'value' => (string) $values['site_title']],
            'site.description' => ['group' => 'site', 'value' => (string) $values['site_description']],
            'dashboard.theme.active' => ['group' => 'dashboard_theme', 'value' => (string) $values['dashboard_theme_active']],
            'theme.active' => ['group' => 'theme', 'value' => (string) $values['theme_active']],
            'comments.enabled' => ['group' => 'comments', 'value' => (bool) $values['comments_enabled']],
            'comments.moderation_required' => ['group' => 'comments', 'value' => (bool) $values['comments_moderation_required']],
            'uploads.url' => ['group' => 'uploads', 'value' => (string) $values['uploads_url']],
            'uploads.max_size_mb' => ['group' => 'uploads', 'value' => (int) $values['uploads_max_size_mb']],
            'cache.driver' => ['group' => 'cache', 'value' => (string) $values['cache_driver']],
        ];

        $this->database->transaction(function (Database $database) use ($map): void {
            foreach ($map as $key => $entry) {
                $payload = json_encode(['value' => $entry['value']], JSON_THROW_ON_ERROR);
                $database->statement(
                    'INSERT INTO settings (key, value, group_name)
                     VALUES (:key, CAST(:value AS JSONB), :group_name)
                     ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, group_name = EXCLUDED.group_name, updated_at = CURRENT_TIMESTAMP',
                    [
                        'key' => $key,
                        'value' => $payload,
                        'group_name' => $entry['group'],
                    ]
                );
            }
        });

        $this->refreshCache();
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    public function availableThemes(): array
    {
        return $this->availableWebsiteThemes();
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    public function availableWebsiteThemes(): array
    {
        return $this->availableThemesBySurface('website');
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    public function availableDashboardThemes(): array
    {
        return $this->availableThemesBySurface('dashboard');
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    private function availableThemesBySurface(string $surface): array
    {
        $themes = [];
        $surfacePath = rtrim($this->themesPath, '/') . '/' . $surface;

        foreach (glob($surfacePath . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $theme = $this->readThemeManifest($directory);

            if ($theme['surface'] !== $surface) {
                continue;
            }

            $themes[] = ['key' => $theme['key'], 'name' => $theme['name']];
        }

        usort($themes, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $themes;
    }

    public function clearCache(): void
    {
        $this->cache->flush();
    }

    public function refreshCache(): void
    {
        $this->cache->forget(self::SETTINGS_CACHE_KEY);
        $this->cache->forget(self::SITE_CONTEXT_CACHE_KEY);
        $this->cache->forget(self::THEMES_CACHE_KEY);
        $this->cache->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'site.title' => 'MidexCMS Site',
            'site.description' => 'Simple website powered by MidexCMS',
            'dashboard.theme.active' => 'default',
            'theme.active' => 'default',
            'comments.enabled' => true,
            'comments.moderation_required' => true,
            'uploads.url' => (string) ($this->config['uploads']['url'] ?? '/uploads'),
            'uploads.max_size_mb' => (int) ($this->config['uploads']['max_size_mb'] ?? 10),
            'cache.driver' => (string) ($this->config['cache']['driver'] ?? 'file'),
        ];
    }

    /**
     * @return array{key: string, name: string, surface: string}
     */
    private function readThemeManifest(string $directory): array
    {
        $key = basename($directory);
        $manifestPath = $directory . '/theme.json';
        $name = $key;
        $surface = basename(dirname($directory)) === 'dashboard' ? 'dashboard' : 'website';

        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);

            if (is_array($decoded)) {
                if (isset($decoded['name']) && is_string($decoded['name'])) {
                    $name = $decoded['name'];
                }

                if (isset($decoded['surface']) && is_string($decoded['surface'])) {
                    $surface = $decoded['surface'];
                }
            }
        }

        return [
            'key' => $key,
            'name' => $name,
            'surface' => $surface,
        ];
    }
}
