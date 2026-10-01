<?php

use Illuminate\Support\Facades\Route;

// API-only application. The React SPA is served by Nginx (or `npm run dev` locally).
Route::get('/', fn () => response()->json([
    'success' => true,
    'data' => ['service' => 'leadflow-api', 'docs' => '/api/v1'],
    'message' => null,
    'errors' => null,
]));
