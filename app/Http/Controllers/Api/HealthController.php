<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

class HealthController extends Controller
{
    use ApiResponse;

    public function index()
    {
        return $this->successResponse([
            'service' => 'accounting-api',
            'status' => 'ok',
            'environment' => app()->environment(),
        ], 'API is running');
    }
}