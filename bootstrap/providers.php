<?php

use App\Providers\AppServiceProvider;
use App\Providers\AliasServiceProvider;
use App\Shared\Providers\SharedServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    AliasServiceProvider::class,
];
