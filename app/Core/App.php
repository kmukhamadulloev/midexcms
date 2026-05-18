<?php

declare(strict_types=1);

namespace MidexCMS\Core;

use InvalidArgumentException;
use MidexCMS\Admin\Controllers\AuthController;
use MidexCMS\Admin\Controllers\AnalyticsController;
use MidexCMS\Admin\Controllers\BackupController;
use MidexCMS\Admin\Controllers\DashboardController;
use MidexCMS\Admin\Controllers\FormController;
use MidexCMS\Admin\Controllers\MediaController;
use MidexCMS\Admin\Controllers\ModulePlaceholderController;
use MidexCMS\Admin\Controllers\MenuController;
use MidexCMS\Admin\Controllers\PageController;
use MidexCMS\Admin\Controllers\CommentController;
use MidexCMS\Admin\Controllers\SettingsController;
use MidexCMS\Core\Admin\AdminView;
use MidexCMS\Core\Cache\CacheInterface;
use MidexCMS\Core\Cache\FileCache;
use MidexCMS\Core\Cache\NullCache;
use MidexCMS\Core\Theme\ThemeLoader;
use MidexCMS\Core\Theme\ThemeRenderer;
use MidexCMS\Http\Controllers\InstallController;
use MidexCMS\Http\Controllers\InteractionController;
use MidexCMS\Http\Controllers\HomeController;
use MidexCMS\Http\Controllers\PublicPageController;
use MidexCMS\Http\Controllers\SeoController;
use MidexCMS\Http\Controllers\ThemeAssetController;
use MidexCMS\Http\Controllers\UploadAssetController;
use MidexCMS\Modules\Analytics\AnalyticsRepository;
use MidexCMS\Modules\Analytics\AnalyticsService;
use MidexCMS\Modules\Backups\BackupService;
use MidexCMS\Modules\Comments\CommentRepository;
use MidexCMS\Modules\Comments\CommentService;
use MidexCMS\Modules\Forms\FormRepository;
use MidexCMS\Modules\Forms\FormService;
use MidexCMS\Modules\Likes\LikeRepository;
use MidexCMS\Modules\Likes\LikeService;
use MidexCMS\Modules\Media\MediaRepository;
use MidexCMS\Modules\Media\MediaService;
use MidexCMS\Modules\Menus\MenuRepository;
use MidexCMS\Modules\Menus\MenuService;
use MidexCMS\Modules\Pages\ContentRenderer;
use MidexCMS\Modules\Pages\PagePreviewSigner;
use MidexCMS\Modules\Pages\PageRepository;
use MidexCMS\Modules\Pages\PageService;
use MidexCMS\Modules\Pages\ShortcodeRenderer;
use MidexCMS\Modules\SEO\SeoService;

final class App
{
    private Config $config;

    private Router $router;

    private ErrorHandler $errorHandler;

    private Session $session;

    private Auth $auth;

    private Csrf $csrf;

    private CacheInterface $cache;

    private RateLimiter $rateLimiter;

    private Flash $flash;

    private ?Database $database = null;

    public function __construct(
        private readonly string $rootPath
    ) {
        $this->config = Config::load($rootPath);
        $logger = new Logger($rootPath . '/storage/logs/app.log');
        $this->errorHandler = new ErrorHandler((bool) $this->config->get('app.debug', false), $logger);
        $this->router = new Router();
        $this->session = new Session((string) $this->config->get('security.session_name', 'midexcms_session'));
        $this->auth = new Auth($this->session);
        $this->csrf = new Csrf($this->session, (string) $this->config->get('security.csrf_token_name', '_csrf'));
        $this->cache = $this->buildCache();
        $this->rateLimiter = new RateLimiter($this->cache);
        $this->flash = new Flash($this->session);
    }

    public function run(): void
    {
        $this->errorHandler->register();
        $this->session->start();
        $this->registerRoutes();
        $this->router->dispatch(Request::capture())->send();
    }

    private function registerRoutes(): void
    {
        if (!$this->isInstalled()) {
            $this->registerConfiguredRoutes($this->configuredRoutes('install'));

            return;
        }

        $this->registerConfiguredRoutes($this->configuredRoutes('app'));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function flash(): Flash
    {
        return $this->flash;
    }

    public function settings(): SettingsService
    {
        return new SettingsService(
            $this->database(),
            $this->cache,
            $this->rootPath . '/content/themes',
            $this->config->all(),
        );
    }

    public function database(): Database
    {
        if ($this->database instanceof Database) {
            return $this->database;
        }

        $this->database = new Database((array) $this->config->get('database', []));

        return $this->database;
    }

    private function buildCache(): CacheInterface
    {
        $driver = (string) $this->config->get('cache.driver', 'file');

        if ($driver === 'file') {
            return new FileCache((string) $this->config->get('cache.path', $this->rootPath . '/content/cache'));
        }

        return new NullCache();
    }

    private function isInstalled(): bool
    {
        return is_file($this->rootPath . '/storage/installed.lock');
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     */
    private function registerConfiguredRoutes(array $routes): void
    {
        foreach ($routes as $route) {
            $methods = array_map('strtoupper', (array) ($route['methods'] ?? ['GET']));
            $path = (string) ($route['path'] ?? '/');
            $type = (string) ($route['type'] ?? 'controller');

            $this->router->match($methods, $path, match ($type) {
                'controller' => $this->controllerHandler($route),
                'redirect' => $this->redirectHandler($route),
                'health' => $this->healthHandler(),
                default => throw new InvalidArgumentException(sprintf('Unsupported route type "%s" for path "%s".', $type, $path)),
            });
        }
    }

    /**
     * @return array<string, callable(): object>
     */
    private function controllerRegistry(): array
    {
        return [
            'install' => fn (): InstallController => $this->makeInstallController(),
            'auth' => fn (): AuthController => $this->makeAuthController(),
            'dashboard' => fn (): DashboardController => $this->makeDashboardController(),
            'analytics' => fn (): AnalyticsController => $this->makeAnalyticsController(),
            'backup' => fn (): BackupController => $this->makeBackupController(),
            'module_placeholder' => fn (): ModulePlaceholderController => $this->makeModulePlaceholderController(),
            'settings' => fn (): SettingsController => $this->makeSettingsController(),
            'page' => fn (): PageController => $this->makePageController(),
            'form' => fn (): FormController => $this->makeFormController(),
            'comment' => fn (): CommentController => $this->makeCommentController(),
            'public_page' => fn (): PublicPageController => $this->makePublicPageController(),
            'interaction' => fn (): InteractionController => $this->makeInteractionController(),
            'seo' => fn (): SeoController => $this->makeSeoController(),
            'theme_asset' => fn (): ThemeAssetController => $this->makeThemeAssetController(),
            'dashboard_theme_asset' => fn (): ThemeAssetController => $this->makeDashboardThemeAssetController(),
            'upload_asset' => fn (): UploadAssetController => $this->makeUploadAssetController(),
            'media' => fn (): MediaController => $this->makeMediaController(),
            'menu' => fn (): MenuController => $this->makeMenuController(),
        ];
    }

    /**
     * @param array<string, mixed> $route
     */
    private function controllerHandler(array $route): callable
    {
        $controllerKey = (string) ($route['controller'] ?? '');
        $action = (string) ($route['action'] ?? '');
        $defaults = (array) ($route['defaults'] ?? []);
        $controllers = $this->controllerRegistry();

        if ($controllerKey === '' || $action === '' || !isset($controllers[$controllerKey])) {
            throw new InvalidArgumentException(sprintf('Route controller "%s::%s" is not registered.', $controllerKey, $action));
        }

        $factory = $controllers[$controllerKey];

        return fn (Request $request, array $parameters): Response => $factory()->{$action}($request, array_replace($defaults, $parameters));
    }

    /**
     * @param array<string, mixed> $route
     */
    private function redirectHandler(array $route): callable
    {
        $target = (string) ($route['to'] ?? '/');
        $status = (int) ($route['status'] ?? 302);

        return fn (Request $request, array $parameters): Response => Response::redirect($this->interpolateRouteTarget($target, $parameters), $status);
    }

    private function healthHandler(): callable
    {
        return fn (Request $request, array $parameters): Response => Response::json([
            'status' => 'ok',
            'cache' => $this->config->get('cache.driver', 'file'),
            'csrf_input' => $this->csrf->inputName(),
            'auth' => $this->auth->check() ? 'authenticated' : 'guest',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configuredRoutes(string $section): array
    {
        $path = $this->rootPath . '/config/routes.php';

        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Routes configuration file not found: %s', $path));
        }

        $config = require $path;

        if (!is_array($config) || !isset($config[$section]) || !is_array($config[$section])) {
            throw new InvalidArgumentException(sprintf('Routes configuration section "%s" is invalid.', $section));
        }

        return $config[$section];
    }

    /**
     * @param array<string, string> $parameters
     */
    private function interpolateRouteTarget(string $target, array $parameters): string
    {
        return preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/', static function (array $matches) use ($parameters): string {
            $name = $matches[1];

            return isset($parameters[$name]) ? rawurlencode((string) $parameters[$name]) : '';
        }, $target) ?? $target;
    }

    private function makeInstallController(): InstallController
    {
        return new InstallController(
            $this->rootPath,
            $this->session,
            $this->csrf,
            new Validator(),
            new Sanitizer(),
        );
    }

    private function makeAuthController(): AuthController
    {
        return new AuthController(
            $this->database(),
            $this->session,
            $this->auth,
            $this->csrf,
            $this->flash,
            new Validator(),
            $this->rateLimiter,
            $this->config,
            $this->makeAdminView(),
        );
    }

    private function makeDashboardController(): DashboardController
    {
        return new DashboardController(
            $this->database(),
            $this->auth,
            $this->csrf,
            $this->flash,
            $this->settings(),
            $this->analyticsService(),
            $this->makeAdminView(),
        );
    }

    private function makeAnalyticsController(): AnalyticsController
    {
        return new AnalyticsController(
            $this->auth,
            $this->flash,
            $this->analyticsService(),
            $this->makeAdminView(),
        );
    }

    private function makeBackupController(): BackupController
    {
        return new BackupController(
            $this->auth,
            $this->csrf,
            $this->flash,
            $this->backupService(),
            $this->makeAdminView(),
        );
    }

    private function makeModulePlaceholderController(): ModulePlaceholderController
    {
        return new ModulePlaceholderController(
            $this->auth,
            $this->flash,
            $this->makeAdminView(),
        );
    }

    private function makeSettingsController(): SettingsController
    {
        return new SettingsController(
            $this->auth,
            $this->csrf,
            $this->flash,
            new Validator(),
            new Sanitizer(),
            $this->settings(),
            $this->makeAdminView(),
        );
    }

    private function makeHomeController(): HomeController
    {
        return new HomeController($this->settings());
    }

    private function makePageController(): PageController
    {
        return new PageController(
            $this->auth,
            $this->csrf,
            $this->flash,
            new Validator(),
            new Sanitizer(),
            $this->pageService(),
            $this->makeAdminView(),
        );
    }

    private function makeFormController(): FormController
    {
        return new FormController(
            $this->auth,
            $this->csrf,
            $this->flash,
            new Sanitizer(),
            $this->formService(),
            $this->makeAdminView(),
        );
    }

    private function makeCommentController(): CommentController
    {
        return new CommentController(
            $this->auth,
            $this->csrf,
            $this->flash,
            $this->commentService(),
            $this->makeAdminView(),
        );
    }

    private function makePublicPageController(): PublicPageController
    {
        return new PublicPageController(
            $this->pageService(),
            $this->analyticsService(),
            $this->themeRenderer(),
        );
    }

    private function makeInteractionController(): InteractionController
    {
        return new InteractionController(
            $this->csrf,
            $this->flash,
            $this->rateLimiter,
            $this->formService(),
            $this->commentService(),
            $this->likeService(),
            $this->pageService(),
        );
    }

    private function makeThemeAssetController(): ThemeAssetController
    {
        return new ThemeAssetController(
            $this->themeLoader(),
        );
    }

    private function makeDashboardThemeAssetController(): ThemeAssetController
    {
        return new ThemeAssetController(
            $this->dashboardThemeLoader(),
        );
    }

    private function makeSeoController(): SeoController
    {
        return new SeoController(
            $this->seoService(),
        );
    }

    private function makeUploadAssetController(): UploadAssetController
    {
        return new UploadAssetController(
            $this->mediaService(),
        );
    }

    private function pageService(): PageService
    {
        return new PageService(
            new PageRepository($this->database()),
            new ContentRenderer(new Sanitizer()),
            new PagePreviewSigner(
                (string) $this->config->get('app.url', 'midexcms') . '|' . (string) $this->config->get('security.session_name', 'midexcms_session')
            ),
            $this->cache,
        );
    }

    private function formService(): FormService
    {
        return new FormService(
            new FormRepository($this->database()),
            $this->cache,
        );
    }

    private function commentService(): CommentService
    {
        return new CommentService(
            new CommentRepository($this->database()),
            $this->settings(),
            $this->cache,
        );
    }

    private function likeService(): LikeService
    {
        return new LikeService(
            new LikeRepository($this->database()),
            $this->cache,
        );
    }

    private function analyticsService(): AnalyticsService
    {
        return new AnalyticsService(
            new AnalyticsRepository($this->database()),
        );
    }

    private function backupService(): BackupService
    {
        return new BackupService(
            $this->database(),
            $this->cache,
            $this->rootPath . '/content/backups',
            $this->rootPath . '/content/uploads',
        );
    }

    private function seoService(): SeoService
    {
        return new SeoService(
            new PageRepository($this->database()),
            $this->cache,
            (string) $this->config->get('app.url', 'http://localhost'),
        );
    }

    private function mediaService(): MediaService
    {
        return new MediaService(
            new MediaRepository($this->database()),
            $this->cache,
            (string) $this->config->get('uploads.path', $this->rootPath . '/content/uploads'),
            (string) $this->settings()->get('uploads.url', $this->config->get('uploads.url', '/uploads')),
            (int) $this->settings()->get('uploads.max_size_mb', $this->config->get('uploads.max_size_mb', 10)),
        );
    }

    private function menuService(): MenuService
    {
        return new MenuService(
            new MenuRepository($this->database()),
            $this->cache,
        );
    }

    private function themeLoader(): ThemeLoader
    {
        return new ThemeLoader($this->rootPath . '/content/themes/website', $this->cache);
    }

    private function dashboardThemeLoader(): ThemeLoader
    {
        return new ThemeLoader($this->rootPath . '/content/themes/dashboard', $this->cache);
    }

    private function themeRenderer(): ThemeRenderer
    {
        return new ThemeRenderer(
            $this->themeLoader(),
            new TemplateEngine($this->themeLoader()),
            $this->settings(),
            $this->cache,
            $this->menuService(),
            new ContentRenderer(new Sanitizer()),
            new ShortcodeRenderer($this->formService(), $this->pageService(), $this->mediaService()),
            $this->commentService(),
            $this->likeService(),
            $this->seoService(),
            $this->csrf,
            $this->flash,
        );
    }

    private function makeMediaController(): MediaController
    {
        return new MediaController(
            $this->auth,
            $this->csrf,
            $this->flash,
            new Sanitizer(),
            $this->mediaService(),
            $this->makeAdminView(),
        );
    }

    private function makeMenuController(): MenuController
    {
        return new MenuController(
            $this->auth,
            $this->csrf,
            $this->flash,
            new Sanitizer(),
            $this->menuService(),
            $this->makeAdminView(),
        );
    }

    private function makeAdminView(): AdminView
    {
        $themeKey = (string) $this->settings()->get('dashboard.theme.active', 'default');
        $themePath = $this->rootPath . '/content/themes/dashboard/' . $themeKey;

        if (!is_dir($themePath)) {
            $themeKey = 'default';
            $themePath = $this->rootPath . '/content/themes/dashboard/default';
        }

        return new AdminView(
            $themePath,
            $themeKey,
        );
    }
}
