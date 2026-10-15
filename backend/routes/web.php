<?php

use App\Http\Controllers\Api\V1\GoogleOAuthController;
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

/*
 * Where Google sends the browser back to.
 *
 * On the web routes and unauthenticated, because that is what a redirect from
 * another origin is: it carries no token of ours. Everything it trusts comes
 * out of the signed `state` it was given at the start, and it always ends by
 * putting the person back on the screen they left.
 *
 * Throttled, because it is a public URL that does network work.
 */
Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('oauth.google.callback');
