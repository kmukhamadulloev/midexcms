<?php

declare(strict_types=1);

namespace PlainCMS\Http\Controllers;

use PlainCMS\Core\Csrf;
use PlainCMS\Core\Install\EnvironmentChecker;
use PlainCMS\Core\Install\InstallerService;
use PlainCMS\Core\Install\InstallerState;
use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Core\Sanitizer;
use PlainCMS\Core\Session;
use PlainCMS\Core\Validator;
use Throwable;

final class InstallController
{
    public function __construct(
        private readonly string $rootPath,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly Validator $validator,
        private readonly Sanitizer $sanitizer
    ) {
    }

    public function index(Request $request, array $parameters = []): Response
    {
        $service = $this->service();

        if ($service->installed()) {
            return Response::html('<h1>Installer Locked</h1><p>This application is already installed.</p>', 404);
        }

        $state = $this->state();
        $flash = $this->pullFlash();
        $checks = $state->get('checks');
        $database = $state->get('database');
        $admin = $state->get('admin');
        $site = $state->get('site');

        $html = $this->renderPage($checks, $database, $admin, $site, $state->readyForFinish(), $flash);

        return Response::html($html);
    }

    public function check(Request $request, array $parameters = []): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->redirectWithFlash('error', 'CSRF verification failed for environment checks.');
        }

        $checker = new EnvironmentChecker($this->rootPath, [
            'content/uploads',
            'content/cache',
            'content/backups',
            'storage/logs',
            'config',
        ]);

        $result = $checker->run();
        $this->state()->put('checks', [
            'passed' => $result->passes(),
            'items' => $result->checks(),
        ]);

        return $this->redirectWithFlash($result->passes() ? 'success' : 'error', $result->passes()
            ? 'Environment checks passed.'
            : 'Environment checks failed. Review the checklist below.');
    }

    public function database(Request $request, array $parameters = []): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->redirectWithFlash('error', 'CSRF verification failed for database setup.');
        }

        $payload = [
            'host' => (string) $request->input('host', ''),
            'port' => (string) $request->input('port', '5432'),
            'database' => (string) $request->input('database', ''),
            'username' => (string) $request->input('username', ''),
            'password' => (string) $request->input('password', ''),
            'driver' => 'pgsql',
            'charset' => 'utf8',
        ];

        $errors = $this->validator->validate($payload, [
            'host' => ['required', 'string'],
            'port' => ['required', 'string'],
            'database' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if ($errors !== []) {
            return $this->redirectWithFlash('error', $this->flattenErrors($errors));
        }

        try {
            $this->service()->checkDatabase($payload);
            $this->state()->put('database', $payload);
        } catch (Throwable $exception) {
            return $this->redirectWithFlash('error', 'Database connection failed. Check the credentials and PostgreSQL availability.');
        }

        return $this->redirectWithFlash('success', 'Database connection succeeded.');
    }

    public function admin(Request $request, array $parameters = []): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->redirectWithFlash('error', 'CSRF verification failed for admin setup.');
        }

        $name = (string) $request->input('name', '');
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        $passwordConfirmation = (string) $request->input('password_confirmation', '');

        $errors = $this->validator->validate([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:190'],
        ]);

        if ($password !== $passwordConfirmation) {
            $errors['password_confirmation'][] = 'Password confirmation does not match.';
        }

        if ($errors !== []) {
            return $this->redirectWithFlash('error', $this->flattenErrors($errors));
        }

        $this->state()->put('admin', [
            'name' => $this->sanitizer->text($name),
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        return $this->redirectWithFlash('success', 'Admin account details saved.');
    }

    public function site(Request $request, array $parameters = []): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->redirectWithFlash('error', 'CSRF verification failed for site setup.');
        }

        $payload = [
            'site_name' => (string) $request->input('site_name', 'MidexCMS'),
            'site_title' => (string) $request->input('site_title', 'MidexCMS Site'),
            'site_description' => (string) $request->input('site_description', 'Simple website powered by MidexCMS'),
            'site_url' => (string) $request->input('site_url', 'http://midexcms.local'),
        ];

        $errors = $this->validator->validate($payload, [
            'site_name' => ['required', 'string', 'max:190'],
            'site_title' => ['required', 'string', 'max:190'],
            'site_description' => ['required', 'string', 'max:500'],
            'site_url' => ['required', 'string', 'max:500'],
        ]);

        if ($errors !== []) {
            return $this->redirectWithFlash('error', $this->flattenErrors($errors));
        }

        $payload['site_name'] = $this->sanitizer->text($payload['site_name']);
        $payload['site_title'] = $this->sanitizer->text($payload['site_title']);
        $payload['site_description'] = $this->sanitizer->text($payload['site_description']);
        $payload['site_url'] = trim($payload['site_url']);
        $this->state()->put('site', $payload);

        return $this->redirectWithFlash('success', 'Site details saved.');
    }

    public function finish(Request $request, array $parameters = []): Response
    {
        if (!$this->verifyCsrf($request)) {
            return $this->redirectWithFlash('error', 'CSRF verification failed for installation.');
        }

        $state = $this->state();

        if (!$state->readyForFinish()) {
            return $this->redirectWithFlash('error', 'Complete all installer sections before finishing.');
        }

        $checks = $state->get('checks');

        if (($checks['passed'] ?? false) !== true) {
            return $this->redirectWithFlash('error', 'Environment checks must pass before installation can finish.');
        }

        try {
            $this->service()->install(
                $state->get('database'),
                $state->get('admin'),
                $state->get('site'),
            );
            $state->clear();
        } catch (Throwable $exception) {
            return $this->redirectWithFlash('error', 'Installation failed: ' . $exception->getMessage());
        }

        return Response::html(
            '<main style="max-width:760px;margin:48px auto;padding:0 20px;font-family:Georgia, \'Times New Roman\', serif;">'
            . '<p style="letter-spacing:0.14em;text-transform:uppercase;color:#8a7254;">Installation complete</p>'
            . '<h1>MidexCMS is ready.</h1>'
            . '<p>Your default pages, contact form, and main navigation have been created. You can sign in to the admin panel or visit the public site now.</p>'
            . '<p><a href="/admin/login">Open admin login</a> · <a href="/">View site</a></p>'
            . '</main>'
        );
    }

    private function service(): InstallerService
    {
        return new InstallerService($this->rootPath, $this->sanitizer);
    }

    private function state(): InstallerState
    {
        return new InstallerState($this->session);
    }

    private function verifyCsrf(Request $request): bool
    {
        return $this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''));
    }

    private function redirectWithFlash(string $type, string $message): Response
    {
        $this->session->put('flash', ['type' => $type, 'message' => $message]);

        return Response::redirect('/install');
    }

    private function pullFlash(): ?array
    {
        $flash = $this->session->get('flash');
        $this->session->forget('flash');

        return is_array($flash) ? $flash : null;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private function flattenErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = $message;
            }
        }

        return implode(' ', $messages);
    }

    /**
     * @param array<string, mixed> $checks
     * @param array<string, mixed> $database
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $site
     */
    private function renderPage(array $checks, array $database, array $admin, array $site, bool $readyForFinish, ?array $flash): string
    {
        $token = htmlspecialchars($this->csrf->token(), ENT_QUOTES, 'UTF-8');
        $flashHtml = '';

        if ($flash !== null) {
            $flashClass = $flash['type'] === 'success' ? '#d1fae5' : '#fee2e2';
            $flashHtml = sprintf(
                '<div style="padding:12px 14px;border-radius:10px;background:%s;margin-bottom:18px;">%s</div>',
                $flashClass,
                htmlspecialchars((string) $flash['message'], ENT_QUOTES, 'UTF-8'),
            );
        }

        $checkItems = '';

        foreach (($checks['items'] ?? []) as $check) {
            $checkItems .= sprintf(
                '<li><strong>%s</strong>: %s (%s)</li>',
                htmlspecialchars((string) $check['name'], ENT_QUOTES, 'UTF-8'),
                $check['ok'] ? 'OK' : 'Fail',
                htmlspecialchars((string) $check['detail'], ENT_QUOTES, 'UTF-8'),
            );
        }

        $checksSummary = $checkItems === ''
            ? '<p>No environment check has been run yet.</p>'
            : '<ul>' . $checkItems . '</ul>';

        $databaseHost = htmlspecialchars((string) ($database['host'] ?? 'postgres'), ENT_QUOTES, 'UTF-8');
        $databasePort = htmlspecialchars((string) ($database['port'] ?? '5432'), ENT_QUOTES, 'UTF-8');
        $databaseName = htmlspecialchars((string) ($database['database'] ?? 'midexcms'), ENT_QUOTES, 'UTF-8');
        $databaseUser = htmlspecialchars((string) ($database['username'] ?? 'postgres'), ENT_QUOTES, 'UTF-8');
        $adminName = htmlspecialchars((string) ($admin['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $adminEmail = htmlspecialchars((string) ($admin['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $siteName = htmlspecialchars((string) ($site['site_name'] ?? 'MidexCMS'), ENT_QUOTES, 'UTF-8');
        $siteTitle = htmlspecialchars((string) ($site['site_title'] ?? 'MidexCMS Site'), ENT_QUOTES, 'UTF-8');
        $siteDescription = htmlspecialchars((string) ($site['site_description'] ?? 'Simple website powered by MidexCMS'), ENT_QUOTES, 'UTF-8');
        $siteUrl = htmlspecialchars((string) ($site['site_url'] ?? 'http://midexcms.local'), ENT_QUOTES, 'UTF-8');
        $disabled = $readyForFinish ? '' : 'disabled';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MidexCMS Installer</title>
    <style>
        body { font-family: Georgia, "Times New Roman", serif; margin: 0; background: #f6f3ec; color: #1f2937; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 32px 18px 56px; }
        .hero { margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; }
        .card { background: #fffdf8; border: 1px solid #e5dccb; border-radius: 16px; padding: 18px; box-shadow: 0 10px 30px rgba(73, 51, 15, 0.05); }
        h1, h2 { margin-top: 0; }
        label { display: block; font-size: 14px; margin: 10px 0 4px; }
        input, textarea, button { width: 100%; box-sizing: border-box; font: inherit; }
        input, textarea { border: 1px solid #ccbfa5; border-radius: 10px; padding: 10px 12px; background: white; }
        textarea { min-height: 90px; resize: vertical; }
        button { margin-top: 12px; border: 0; border-radius: 999px; padding: 11px 16px; background: #1f2937; color: white; cursor: pointer; }
        button[disabled] { opacity: 0.55; cursor: not-allowed; }
        .status { font-size: 14px; color: #6b7280; }
        .inline { display: grid; grid-template-columns: 1fr 120px; gap: 12px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="hero">
            <h1>MidexCMS Installer</h1>
            <p>Phase 3 bootstrap flow: check the environment, verify PostgreSQL, define the first admin, set site defaults, then write config, schema, seed data, and the install lock.</p>
        </div>
        {$flashHtml}
        <div class="grid">
            <section class="card">
                <h2>1. Environment</h2>
                <p class="status">Checks PHP 8.2+, PDO, pdo_pgsql, and required writable directories.</p>
                {$checksSummary}
                <form method="post" action="/install/check">
                    <input type="hidden" name="_csrf" value="{$token}">
                    <button type="submit">Run environment checks</button>
                </form>
            </section>
            <section class="card">
                <h2>2. Database</h2>
                <form method="post" action="/install/database">
                    <input type="hidden" name="_csrf" value="{$token}">
                    <label for="host">Host</label>
                    <input id="host" name="host" value="{$databaseHost}">
                    <div class="inline">
                        <div>
                            <label for="database">Database</label>
                            <input id="database" name="database" value="{$databaseName}">
                        </div>
                        <div>
                            <label for="port">Port</label>
                            <input id="port" name="port" value="{$databasePort}">
                        </div>
                    </div>
                    <label for="username">Username</label>
                    <input id="username" name="username" value="{$databaseUser}">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password">
                    <button type="submit">Verify database connection</button>
                </form>
            </section>
            <section class="card">
                <h2>3. Admin</h2>
                <form method="post" action="/install/admin">
                    <input type="hidden" name="_csrf" value="{$token}">
                    <label for="name">Name</label>
                    <input id="name" name="name" value="{$adminName}">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{$adminEmail}">
                    <label for="password_admin">Password</label>
                    <input id="password_admin" name="password" type="password">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password">
                    <button type="submit">Save admin details</button>
                </form>
            </section>
            <section class="card">
                <h2>4. Site</h2>
                <form method="post" action="/install/site">
                    <input type="hidden" name="_csrf" value="{$token}">
                    <label for="site_name">Application name</label>
                    <input id="site_name" name="site_name" value="{$siteName}">
                    <label for="site_title">Site title</label>
                    <input id="site_title" name="site_title" value="{$siteTitle}">
                    <label for="site_description">Site description</label>
                    <textarea id="site_description" name="site_description">{$siteDescription}</textarea>
                    <label for="site_url">Site URL</label>
                    <input id="site_url" name="site_url" value="{$siteUrl}">
                    <button type="submit">Save site details</button>
                </form>
            </section>
        </div>
        <section class="card" style="margin-top: 18px;">
            <h2>5. Finish installation</h2>
            <p class="status">This will run SQL migrations, seed the default theme and content, write <code>config/config.php</code>, and create <code>storage/installed.lock</code>.</p>
            <form method="post" action="/install/finish">
                <input type="hidden" name="_csrf" value="{$token}">
                <button type="submit" {$disabled}>Finish installation</button>
            </form>
        </section>
    </div>
</body>
</html>
HTML;
    }
}
