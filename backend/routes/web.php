<?php

use App\Http\Controllers\Api\V1\SharedFileController;
use Illuminate\Support\Facades\Route;

// API-only application. The React SPA is served by Nginx (or `npm run dev` locally).
Route::get('/', fn () => response()->json([
    'success' => true,
    'data' => ['service' => 'leadflow-api', 'docs' => '/api/v1'],
    'message' => null,
    'errors' => null,
]));

/*
 * The link a customer opens.
 *
 * On the web routes rather than under /api because it is a URL a person taps in
 * a WhatsApp message, not something a client calls — and `/f/{token}` fits in a
 * message without wrapping, which a signed API path would not.
 */
Route::get('/f/{token}', SharedFileController::class)
    ->middleware('throttle:60,1')
    ->name('files.share');
