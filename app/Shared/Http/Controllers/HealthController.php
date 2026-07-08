<?php

namespace App\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;

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