<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\SharedFileController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/f/<token>` — the address of a landing form, the short one a marketer pastes into an ad. The page itself is the
 * app's; this hands the visitor on to it with whatever the click carried (campaign tags, the visitor's identity).
 * The token is not checked here: the page asks the API, which answers the same "not found" for every miss.
 */
class FormLinkController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        // The longer tokens are shared files, sent before `/s` existed; they keep opening.
        if (strlen($token) >= 40) {
            return app(SharedFileController::class)($request, $token);
        }

        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $query = $request->getQueryString();

        return redirect()->away($base.'/l/'.$token.($query ? '?'.$query : ''), 302);
    }
}
