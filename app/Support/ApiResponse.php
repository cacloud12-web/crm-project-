<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::normalizeData($data),
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created successfully'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function error(string $message, int $status = 400, mixed $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            if (is_array($errors) && array_key_exists('can_delete', $errors)) {
                $payload['data'] = $errors;
            } else {
                $payload['errors'] = $errors;
            }
        }

        return response()->json($payload, $status);
    }

    private static function normalizeData(mixed $data): mixed
    {
        if ($data instanceof ResourceCollection) {
            return $data->resolve();
        }
        if ($data instanceof JsonResource) {
            return $data->resolve();
        }

        return $data;
    }
}
