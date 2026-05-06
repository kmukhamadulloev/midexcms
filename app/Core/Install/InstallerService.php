<?php

declare(strict_types=1);

namespace PlainCMS\Core\Install;

use PlainCMS\Core\Database;
use PlainCMS\Core\MigrationRunner;
use PlainCMS\Core\Sanitizer;
use PlainCMS\Core\Support\Str;
use RuntimeException;

final class InstallerService
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Sanitizer $sanitizer
    ) {
    }

    public function installed(): bool
    {
        return is_file($this->lockPath());
    }

    public function checkDatabase(array $databaseConfig): void
    {
        $database = new Database($databaseConfig);
        $database->selectOne('SELECT 1 AS ok');
    }

    public function install(array $databaseConfig, array $adminData, array $siteData): void
    {
        if ($this->installed()) {
            throw new RuntimeException('The application is already installed.');
        }

        $database = new Database($databaseConfig);
        $runner = new MigrationRunner($database, $this->rootPath . '/database/migrations');
        $runner->runAll();

        $database->transaction(function (Database $db) use ($adminData, $siteData): void {
            $userId = $this->createAdminUser($db, $adminData);
            $this->seedPages($db, $siteData);
            $this->seedMainMenu($db);
            $this->upsertSetting($db, 'site.title', ['value' => $siteData['site_title']], 'site');
            $this->upsertSetting($db, 'site.description', ['value' => $siteData['site_description']], 'site');
            $this->upsertSetting($db, 'theme.active', ['value' => 'default'], 'theme');
            $this->upsertSetting($db, 'site.installed_user_id', ['value' => $userId], 'site');
        });

        $this->writeConfig($databaseConfig, $siteData);
        $this->writeLockFile();
    }

    private function createAdminUser(Database $database, array $adminData): int
    {
        $database->statement(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)',
            [
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'password_hash' => $adminData['password_hash'],
            ]
        );

        $user = $database->selectOne('SELECT id FROM users WHERE email = :email', ['email' => $adminData['email']]);

        if ($user === null) {
            throw new RuntimeException('Failed to create the first admin user.');
        }

        return (int) $user['id'];
    }

    private function seedPages(Database $database, array $siteData): void
    {
        $pages = [
            ['title' => 'Home', 'path' => '/', 'type' => 'page', 'content_raw' => '# Welcome to your new site', 'comments_enabled' => false],
            ['title' => 'About', 'path' => '/about', 'type' => 'page', 'content_raw' => 'Tell visitors who you are and what you do.', 'comments_enabled' => false],
            ['title' => 'Contact', 'path' => '/contact', 'type' => 'contact', 'content_raw' => '[contact_form id="contact"]', 'comments_enabled' => false],
            ['title' => 'Blog', 'path' => '/blog', 'type' => 'page', 'content_raw' => "This section lists child pages published under Blog.\n\n[child_pages]", 'comments_enabled' => false],
        ];

        foreach ($pages as $page) {
            $slug = $page['path'] === '/' ? 'home' : Str::slug(basename($page['path']));
            $database->statement(
                'INSERT INTO pages (type, title, slug, path, excerpt, content_mode, content_raw, content_html, status, template, comments_enabled, published_at)
                 VALUES (:type, :title, :slug, :path, :excerpt, :content_mode, :content_raw, :content_html, :status, :template, :comments_enabled, CURRENT_TIMESTAMP)',
                [
                    'type' => $page['type'],
                    'title' => $page['title'],
                    'slug' => $slug,
                    'path' => $page['path'],
                    'excerpt' => $siteData['site_description'],
                    'content_mode' => 'markdown',
                    'content_raw' => $page['content_raw'],
                    'content_html' => null,
                    'status' => 'published',
                    'template' => $page['template'] ?? ($page['path'] === '/' ? 'home' : null),
                    'comments_enabled' => $page['comments_enabled'],
                ]
            );
        }
    }

    private function seedMainMenu(Database $database): void
    {
        $menu = $database->selectOne('SELECT id FROM menus WHERE key = :key LIMIT 1', ['key' => 'main']);

        if ($menu === null) {
            $database->statement(
                'INSERT INTO menus (key, name) VALUES (:key, :name)',
                ['key' => 'main', 'name' => 'Main Menu']
            );
            $menu = $database->selectOne('SELECT id FROM menus WHERE key = :key LIMIT 1', ['key' => 'main']);
        }

        if ($menu === null) {
            throw new RuntimeException('Failed to create the default main menu.');
        }

        $existingItems = $database->selectOne(
            'SELECT id FROM menu_items WHERE menu_id = :menu_id LIMIT 1',
            ['menu_id' => (int) $menu['id']]
        );

        if ($existingItems !== null) {
            return;
        }

        $paths = ['/', '/about', '/blog', '/contact'];
        $pages = $database->select(
            'SELECT id, title, path FROM pages WHERE path IN (:home, :about, :blog, :contact) ORDER BY path ASC',
            [
                'home' => $paths[0],
                'about' => $paths[1],
                'blog' => $paths[2],
                'contact' => $paths[3],
            ]
        );
        $pageMap = [];

        foreach ($pages as $page) {
            $pageMap[(string) $page['path']] = $page;
        }

        $items = [
            ['path' => '/', 'label' => 'Home'],
            ['path' => '/about', 'label' => 'About'],
            ['path' => '/blog', 'label' => 'Blog'],
            ['path' => '/contact', 'label' => 'Contact'],
        ];

        foreach ($items as $index => $item) {
            $page = $pageMap[$item['path']] ?? null;

            if (!is_array($page) || !isset($page['id'])) {
                continue;
            }

            $database->statement(
                'INSERT INTO menu_items (menu_id, parent_id, page_id, label, url, target, sort_order)
                 VALUES (:menu_id, NULL, :page_id, :label, NULL, :target, :sort_order)',
                [
                    'menu_id' => (int) $menu['id'],
                    'page_id' => (int) $page['id'],
                    'label' => $item['label'],
                    'target' => '_self',
                    'sort_order' => $index + 1,
                ]
            );
        }
    }

    private function upsertSetting(Database $database, string $key, array $value, string $group): void
    {
        $database->statement(
            'UPDATE settings SET value = CAST(:value AS JSONB), group_name = :group_name, updated_at = CURRENT_TIMESTAMP WHERE key = :key',
            [
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'group_name' => $group,
            ]
        );

        $existing = $database->selectOne('SELECT id FROM settings WHERE key = :key', ['key' => $key]);

        if ($existing !== null) {
            return;
        }

        $database->statement(
            'INSERT INTO settings (key, value, group_name) VALUES (:key, CAST(:value AS JSONB), :group_name)',
            [
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'group_name' => $group,
            ]
        );
    }

    private function writeConfig(array $databaseConfig, array $siteData): void
    {
        $configPath = $this->rootPath . '/config/config.php';
        $appName = var_export($this->sanitizer->text((string) $siteData['site_name']), true);
        $appUrl = var_export((string) $siteData['site_url'], true);
        $dbHost = var_export((string) $databaseConfig['host'], true);
        $dbPort = (int) $databaseConfig['port'];
        $dbName = var_export((string) $databaseConfig['database'], true);
        $dbUser = var_export((string) $databaseConfig['username'], true);
        $dbPassword = var_export((string) $databaseConfig['password'], true);
        $contents = <<<PHP
<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => {$appName},
        'env' => 'production',
        'debug' => false,
        'url' => {$appUrl},
    ],
    'database' => [
        'driver' => 'pgsql',
        'host' => {$dbHost},
        'port' => {$dbPort},
        'database' => {$dbName},
        'username' => {$dbUser},
        'password' => {$dbPassword},
        'charset' => 'utf8',
    ],
    'security' => [
        'session_name' => 'midexcms_session',
        'csrf_token_name' => '_csrf',
        'password_reset_ttl_minutes' => 60,
        'login_rate_limit_attempts' => 5,
        'login_rate_limit_minutes' => 15,
    ],
    'cache' => [
        'driver' => 'file',
        'path' => __DIR__ . '/../content/cache',
    ],
    'uploads' => [
        'path' => __DIR__ . '/../content/uploads',
        'url' => '/uploads',
        'max_size_mb' => 10,
    ],
];
PHP;

        file_put_contents($configPath, $contents);
    }

    private function writeLockFile(): void
    {
        file_put_contents($this->lockPath(), "installed_at=" . date(DATE_ATOM) . "\n");
    }

    private function lockPath(): string
    {
        return $this->rootPath . '/storage/installed.lock';
    }
}
