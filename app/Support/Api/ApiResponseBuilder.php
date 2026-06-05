<?php

namespace App\Support\Api;

use App\Support\Transaction\TransactionPolicyResult;
use Illuminate\Http\JsonResponse;

class ApiResponseBuilder
{
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(string $code, ?string $message = null, array $errors = [], int $status = 400, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message ?? ApiErrorCode::message($code),
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    public static function warning(string $code, ?string $message = null, array $errors = [], array $meta = [], int $status = 409): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message ?? ApiErrorCode::message($code),
            'requires_confirmation' => true,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }

    public static function validation(array $errors, string $message = 'The given data was invalid.', array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => ApiErrorCode::VALIDATION_ERROR,
            'message' => $message,
            'errors' => $errors,
            'meta' => $meta,
        ], 422);
    }

    public static function fromPolicyResult(mixed $policyResult, int $denyStatus = 403): JsonResponse
    {
        if (! $policyResult instanceof TransactionPolicyResult) {
            return self::error(ApiErrorCode::UNKNOWN_ERROR, 'Invalid policy result.', [], 500);
        }

        $arr = $policyResult->toArray();
        $code = (string) ($arr['code'] ?? ApiErrorCode::UNKNOWN_ERROR);
        $message = (string) ($arr['message'] ?? ApiErrorCode::message($code));
        $reasons = (array) ($arr['reasons'] ?? []);
        $meta = (array) ($arr['meta'] ?? []);

        if ($policyResult->allowed() && $policyResult->isWarning()) {
            return self::warning($code, $message, $reasons, $meta, 409);
        }

        if ($policyResult->denied()) {
            return self::error($code, $message, $reasons, $denyStatus, $meta);
        }

        return self::success(null, $message, 200, $meta);
    }
}

