<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => config('app.name'),
    'api' => url('/api/v1'),
    'health' => url('/api/v1/health'),
]));
