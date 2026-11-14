<?php

namespace App\Modules\Pos\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Laporan error dari aplikasi kasir (browser). Ditulis ke log stderr supaya
 * muncul di log Render dengan kata kunci `pos.client_error`, tanpa layanan
 * pemantauan tambahan.
 *
 * Rute ini publik (error bisa terjadi sebelum login) dan dibatasi laju, jadi
 * semua isian dipotong dan tidak ada yang dipercaya: hanya dicatat, tidak
 * disimpan ke database dan tidak memengaruhi data toko.
 */
class PosClientErrorController extends PosController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:500'],
            'stack' => ['nullable', 'string', 'max:3000'],
            'source' => ['nullable', 'string', 'in:window,promise,react'],
            'url' => ['nullable', 'string', 'max:300'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ]);

        // Token Sanctum opsional: kalau ada, tahu siapa yang kena (hanya id).
        $user = $request->user('sanctum');

        Log::channel('stderr')->warning('pos.client_error', [
            'message' => $data['message'],
            'source' => $data['source'] ?? 'window',
            'url' => $data['url'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'company_id' => $request->header('X-Company-ID'),
            'user_id' => $user?->id,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 200),
            'stack' => $data['stack'] ?? null,
        ]);

        return $this->successResponse(null, 'Tercatat.');
    }
}
