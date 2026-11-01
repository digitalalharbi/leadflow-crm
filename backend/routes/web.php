<?php

use App\Http\Controllers\Api\V1\GoogleOAuthController;
use App\Http\Controllers\Api\V1\SharedFileController;
use App\Http\Controllers\ApprovalLinkController;
use App\Http\Controllers\EmailTrackingController;
use App\Http\Controllers\SignedExportController;
use App\Http\Controllers\SignedFileController;
use App\Http\Controllers\TrackerScriptController;
use App\Http\Controllers\TrackingRedirectController;
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
/*
 * The approval link sent to a manager's WhatsApp. GET only shows; POST decides,
 * once. See ApprovalLinkController.
 */
Route::get('/approve/{token}', [ApprovalLinkController::class, 'show'])->middleware('throttle:30,1')->where('token', '[A-Za-z0-9]{48}');
Route::post('/approve/{token}', [ApprovalLinkController::class, 'decide'])->middleware('throttle:10,1')->where('token', '[A-Za-z0-9]{48}');

// Staff opening a company file: a five-minute signed link for one file of one company.
Route::get('/files/{file}', SignedFileController::class)
    ->middleware(['signed', 'throttle:60,1'])
    ->whereUuid('file')
    ->name('files.signed');

// A finished export, for the person who made it: the same five-minute signed link as a company file.
Route::get('/files/exports/{export}', SignedExportController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->whereUuid('export')
    ->name('files.export');

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

/*
 * What comes back from an email.
 *
 * An open pixel, a redirecting link and an unsubscribe — all public because the
 * caller is a customer's mail client. Short paths, since they sit inside a
 * message; throttled, since they are public and write.
 */
Route::middleware('throttle:120,1')->group(function () {
    Route::get('/e/o/{token}', [EmailTrackingController::class, 'open'])->name('email.open');
    Route::get('/e/c/{token}', [EmailTrackingController::class, 'click'])->name('email.click');
    Route::match(['get', 'post'], '/e/u/{token}', [EmailTrackingController::class, 'unsubscribe'])->name('email.unsubscribe');
});

/*
 * The tracking link: `/t/{token}` records the click and sends the visitor on. Short, because it is
 * printed on billboards and pasted into messages; throttled, because it is public and writes.
 * `/tracker/{token}.js` is the script a landing page embeds to report what the visitor does next.
 */
Route::get('/t/{token}', TrackingRedirectController::class)->middleware('throttle:300,1')->where('token', '[A-Za-z0-9]{6,24}')->name('tracking.redirect');
Route::get('/tracker/{token}.js', TrackerScriptController::class)->middleware('throttle:120,1')->where('token', '[A-Za-z0-9]{6,24}')->name('tracking.script');
