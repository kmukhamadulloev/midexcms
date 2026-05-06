<?php

declare(strict_types=1);

namespace PlainCMS\Admin\Controllers;

use PlainCMS\Core\Admin\AdminView;
use PlainCMS\Core\Auth;
use PlainCMS\Core\Config;
use PlainCMS\Core\Csrf;
use PlainCMS\Core\Database;
use PlainCMS\Core\Flash;
use PlainCMS\Core\PasswordResetService;
use PlainCMS\Core\RateLimiter;
use PlainCMS\Core\Request;
use PlainCMS\Core\Response;
use PlainCMS\Core\Session;
use PlainCMS\Core\Validator;
use RuntimeException;

final class AuthController
{
    public function __construct(
        private readonly Database $database,
        private readonly Session $session,
        private readonly Auth $auth,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly Validator $validator,
        private readonly RateLimiter $rateLimiter,
        private readonly Config $config,
        private readonly AdminView $view,
    ) {
    }

    public function showLogin(Request $request, array $parameters = []): Response
    {
        if ($this->auth->check()) {
            return Response::redirect('/admin');
        }

        $body = $this->view->authForm(
            'Admin Login',
            'Sign in to manage pages, settings, media, and the rest of the CMS.',
            '/admin/login',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password'],
            ],
            $this->csrf->token(),
            'Sign in',
            $this->flash->pullOldInput(),
            $this->flash->pullErrors(),
        );

        $links = $this->view->authLinks([
            ['label' => 'Forgot your password?', 'href' => '/admin/password-reset'],
        ]);

        return Response::html($this->view->layout('Admin Login', $body . $links, [], $this->flash->pull()));
    }

    public function login(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/login');
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $clientKey = 'login:' . $email . ':' . ($request->server('REMOTE_ADDR', 'unknown'));
        $maxAttempts = (int) $this->config->get('security.login_rate_limit_attempts', 5);
        $windowMinutes = (int) $this->config->get('security.login_rate_limit_minutes', 15);

        if ($this->rateLimiter->tooManyAttempts($clientKey, $maxAttempts)) {
            $this->flash->error('Too many login attempts. Try again later.');

            return Response::redirect('/admin/login');
        }

        $errors = $this->validator->validate([
            'email' => $email,
            'password' => $password,
        ], [
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'max:190'],
        ]);

        if ($errors !== []) {
            $this->flash->withErrors($errors);
            $this->flash->withOldInput(['email' => $email]);
            $this->flash->error('Please fix the login form errors.');

            return Response::redirect('/admin/login');
        }

        $user = $this->database->selectOne('SELECT id, password_hash FROM users WHERE email = :email', ['email' => $email]);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            $this->rateLimiter->hit($clientKey, $windowMinutes * 60);
            $this->flash->withOldInput(['email' => $email]);
            $this->flash->error('The provided credentials are invalid.');

            return Response::redirect('/admin/login');
        }

        $this->rateLimiter->clear($clientKey);
        $this->auth->login((int) $user['id']);
        $this->database->statement('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id', ['id' => (int) $user['id']]);
        $this->flash->success('Welcome back.');

        return Response::redirect('/admin');
    }

    public function logout(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin');
        }

        $this->auth->logout();
        $this->flash->success('You have been signed out.');

        return Response::redirect('/admin/login');
    }

    public function showPasswordResetRequest(Request $request, array $parameters = []): Response
    {
        $body = $this->view->authForm(
            'Reset Password',
            'Request a reset link for the admin account. The response stays generic for safety.',
            '/admin/password-reset',
            [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
            ],
            $this->csrf->token(),
            'Request reset',
            $this->flash->pullOldInput(),
            $this->flash->pullErrors(),
        );

        $links = $this->view->authLinks([
            ['label' => 'Back to login', 'href' => '/admin/login'],
        ]);

        return Response::html($this->view->layout('Reset Password', $body . $links, [], $this->flash->pull()));
    }

    public function requestPasswordReset(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/password-reset');
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $clientKey = 'password_reset:' . $email . ':' . ($request->server('REMOTE_ADDR', 'unknown'));
        $maxAttempts = (int) $this->config->get('security.login_rate_limit_attempts', 5);
        $windowMinutes = (int) $this->config->get('security.login_rate_limit_minutes', 15);

        if ($this->rateLimiter->tooManyAttempts($clientKey, $maxAttempts)) {
            $this->flash->error('Too many password reset attempts. Try again later.');

            return Response::redirect('/admin/password-reset');
        }

        $errors = $this->validator->validate(['email' => $email], [
            'email' => ['required', 'email', 'max:190'],
        ]);

        if ($errors !== []) {
            $this->flash->withErrors($errors);
            $this->flash->withOldInput(['email' => $email]);
            $this->flash->error('Please fix the password reset form.');

            return Response::redirect('/admin/password-reset');
        }

        $resetter = $this->resetService();
        $token = $resetter->issueForEmail($email);
        $this->rateLimiter->hit($clientKey, $windowMinutes * 60);
        $message = 'If that email exists, a password reset token has been generated.';

        if ($token !== null && (bool) $this->config->get('app.debug', false)) {
            $message .= ' Debug reset link: ' . $this->config->get('app.url', '') . '/admin/password-reset/' . $token;
        }

        $this->flash->success($message);

        return Response::redirect('/admin/password-reset');
    }

    public function showPasswordResetConfirm(Request $request, array $parameters = []): Response
    {
        $token = (string) ($parameters['token'] ?? '');
        $valid = $token !== '';

        if ($valid) {
            $reset = $this->resetService()->validate($token);
            $valid = $reset !== null && $reset['used_at'] === null && strtotime((string) $reset['expires_at']) >= time();
        }

        if (!$valid) {
            $this->flash->error('This reset link is invalid or expired.');

            return Response::redirect('/admin/password-reset');
        }

        $body = $this->view->authForm(
            'Choose New Password',
            'Enter the new password for your admin account.',
            '/admin/password-reset/' . rawurlencode($token),
            [
                ['name' => 'password', 'label' => 'New password', 'type' => 'password'],
                ['name' => 'password_confirmation', 'label' => 'Confirm password', 'type' => 'password'],
            ],
            $this->csrf->token(),
            'Update password',
            [],
            $this->flash->pullErrors(),
        );

        return Response::html($this->view->layout('Choose New Password', $body, [], $this->flash->pull()));
    }

    public function confirmPasswordReset(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect('/admin/password-reset');
        }

        $token = (string) ($parameters['token'] ?? '');
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');

        $errors = $this->validator->validate(['password' => $password], [
            'password' => ['required', 'string', 'min:8', 'max:190'],
        ]);

        if ($password !== $confirmation) {
            $errors['password_confirmation'][] = 'Password confirmation does not match.';
        }

        if ($errors !== []) {
            $this->flash->withErrors($errors);
            $this->flash->error('Please fix the password reset form.');

            return Response::redirect('/admin/password-reset/' . rawurlencode($token));
        }

        try {
            $this->resetService()->consume($token, $password);
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());

            return Response::redirect('/admin/password-reset');
        }

        $this->flash->success('Your password has been updated. You can sign in now.');

        return Response::redirect('/admin/login');
    }

    private function resetService(): PasswordResetService
    {
        return new PasswordResetService(
            $this->database,
            (int) $this->config->get('security.password_reset_ttl_minutes', 60),
        );
    }
}
