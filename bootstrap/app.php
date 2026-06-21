<?php

use App\Http\Middleware\EnsureCompanyAccess;
use App\Http\Middleware\EnsurePermission;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponseBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'company.access' => EnsureCompanyAccess::class,
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (ValidationException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponseBuilder::validation($e->errors(), 'Please review the highlighted fields.');
        });

        // Resource tidak ditemukan: kembalikan envelope aman tanpa membocorkan
        // class/file/line/stack trace backend (A13-096).
        $exceptions->renderable(function (ModelNotFoundException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponseBuilder::error(ApiErrorCode::RESOURCE_NOT_FOUND, 'The requested resource was not found.', [], 404);
        });

        $exceptions->renderable(function (NotFoundHttpException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            return ApiResponseBuilder::error(ApiErrorCode::RESOURCE_NOT_FOUND, 'The requested resource was not found.', [], 404);
        });

        $exceptions->renderable(function (QueryException $e, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $message = $e->getMessage();
            $errors = [];
            $status = 500;

            if (str_contains($message, 'NOT NULL constraint failed:')) {
                $status = 422;
                if (preg_match('/NOT NULL constraint failed:\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', $message, $matches) === 1) {
                    $field = $matches[2];
                    $label = str($field)->replace('_', ' ')->title()->toString();
                    $errors[$field] = ["{$label} is required."];
                }
            } elseif (str_contains($message, 'UNIQUE constraint failed:') || str_contains($message, 'Integrity constraint violation')) {
                $status = 422;
                if (preg_match('/UNIQUE constraint failed:\s*([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)/', $message, $matches) === 1) {
                    $field = $matches[2];
                    $label = str($field)->replace('_', ' ')->title()->toString();
                    $errors[$field] = ["{$label} is already in use."];
                }
            }

            $safeMessage = $status === 422
                ? 'Data could not be saved. Please review the highlighted fields.'
                : 'The server could not process the request. Please try again or contact an administrator.';

            return ApiResponseBuilder::error('DATABASE_ERROR', $safeMessage, $errors, $status);
        });
    })->create();
