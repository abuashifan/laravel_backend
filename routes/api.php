<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

require base_path('app/Modules/Auth/Routes/api.php');
require base_path('app/Modules/Companies/Routes/api.php');
require base_path('app/Modules/Tenant/Routes/api.php');
require base_path('app/Modules/Setup/Routes/api.php');
require base_path('app/Modules/Settings/Routes/api.php');
require base_path('app/Modules/Access/Routes/api.php');
require base_path('app/Modules/Accounting/Routes/api.php');
require base_path('app/Modules/OpeningBalance/Routes/api.php');
require base_path('app/Modules/MasterData/Routes/api.php');
require base_path('app/Modules/Journal/Routes/api.php');
require base_path('app/Modules/Reports/Routes/api.php');
require base_path('app/Modules/Sales/Routes/api.php');
require base_path('app/Modules/Purchase/Routes/api.php');
require base_path('app/Modules/CashBank/Routes/api.php');
require base_path('app/Modules/Inventory/Routes/api.php');
require base_path('app/Modules/FixedAssets/Routes/api.php');
