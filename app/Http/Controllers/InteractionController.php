<?php

declare(strict_types=1);

namespace MidexCMS\Http\Controllers;

use MidexCMS\Core\Csrf;
use MidexCMS\Core\Flash;
use MidexCMS\Core\RateLimiter;
use MidexCMS\Core\Request;
use MidexCMS\Core\Response;
use MidexCMS\Modules\Comments\CommentService;
use MidexCMS\Modules\Forms\FormService;
use MidexCMS\Modules\Likes\LikeService;
use MidexCMS\Modules\Pages\PageService;
use RuntimeException;

final class InteractionController
{
    public function __construct(
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly RateLimiter $rateLimiter,
        private readonly FormService $forms,
        private readonly CommentService $comments,
        private readonly LikeService $likes,
        private readonly PageService $pages
    ) {
    }

    public function submitForm(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect($this->backPath($request));
        }

        $formKey = (string) ($parameters['form_key'] ?? '');
        $ip = (string) $request->server('REMOTE_ADDR', 'unknown');
        $rateKey = 'public_form:' . $formKey . ':' . $ip;

        if ($this->rateLimiter->tooManyAttempts($rateKey, 5)) {
            $this->flash->error('Too many form submissions. Try again later.');

            return Response::redirect($this->backPath($request));
        }

        $this->rateLimiter->hit($rateKey, 300);

        try {
            $result = $this->forms->submit(
                $formKey,
                $request->all(),
                ctype_digit((string) $request->input('page_id', '')) ? (int) $request->input('page_id') : null,
                $ip,
                (string) $request->server('HTTP_USER_AGENT', 'unknown')
            );
            $this->flash->success((string) $result['success_message']);
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect($this->backPath($request));
    }

    public function submitComment(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            $this->flash->error('CSRF verification failed.');

            return Response::redirect($this->backPath($request));
        }

        $pageId = (int) ($parameters['page_id'] ?? 0);
        $page = $this->pages->findPage($pageId);

        if ($page === null || (string) ($page['status'] ?? '') !== 'published') {
            $this->flash->error('Page not found.');

            return Response::redirect($this->backPath($request));
        }

        $ip = (string) $request->server('REMOTE_ADDR', 'unknown');
        $rateKey = 'public_comment:' . $pageId . ':' . $ip;

        if ($this->rateLimiter->tooManyAttempts($rateKey, 5)) {
            $this->flash->error('Too many comments. Try again later.');

            return Response::redirect($this->backPath($request) . '#comments');
        }

        $this->rateLimiter->hit($rateKey, 300);

        try {
            $message = $this->comments->submit(
                $page,
                $request->all(),
                $ip,
                (string) $request->server('HTTP_USER_AGENT', 'unknown')
            );
            $this->flash->success($message);
        } catch (RuntimeException $exception) {
            $this->flash->error($exception->getMessage());
        }

        return Response::redirect($this->backPath($request) . '#comments');
    }

    public function toggleLike(Request $request, array $parameters = []): Response
    {
        if (!$this->csrf->verifyToken((string) $request->input($this->csrf->inputName(), ''))) {
            return Response::json(['error' => 'CSRF verification failed.'], 422);
        }

        $pageId = (int) ($parameters['page_id'] ?? 0);
        $page = $this->pages->findPage($pageId);

        if ($page === null || (string) ($page['status'] ?? '') !== 'published') {
            return Response::json(['error' => 'Page not found.'], 404);
        }

        $visitorHash = $this->likes->visitorHash(
            (string) $request->server('REMOTE_ADDR', 'unknown'),
            (string) $request->server('HTTP_USER_AGENT', 'unknown')
        );
        $rateKey = 'public_like:' . $pageId . ':' . $visitorHash;

        if ($this->rateLimiter->tooManyAttempts($rateKey, 20)) {
            return Response::json(['error' => 'Too many like requests.'], 429);
        }

        $this->rateLimiter->hit($rateKey, 60);

        $result = $this->likes->toggle($pageId, $visitorHash);

        return Response::json($result);
    }

    private function backPath(Request $request): string
    {
        $referer = (string) $request->server('HTTP_REFERER', '/');
        $path = parse_url($referer, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
