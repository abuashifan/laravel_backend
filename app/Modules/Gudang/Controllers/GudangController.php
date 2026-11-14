<?php

namespace App\Modules\Gudang\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\User;
use Illuminate\Http\Request;

/** Dasar controller modul Gudang (Fase 0). Bentuk response sama dengan modul lain. */
abstract class GudangController extends Controller
{
    use ApiResponse;

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
