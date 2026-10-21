<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** How the signed-in person wants to be told: each kind of alert at once, later, or not at all; and which summaries. */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request)
    {
        return ApiResponse::success($this->present($request));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'alerts' => ['sometimes', 'array'],
            'alerts.*' => ['string', Rule::in(AlertKinds::MODES)],
            'digests' => ['sometimes', 'array'],
            'digests.*' => ['boolean'],
        ]);

        // Only keys that exist: an unknown category is ignored, not stored.
        NotificationPrefs::save($request->user(), [
            'alerts' => array_intersect_key($data['alerts'] ?? [], array_flip(AlertKinds::CATEGORIES)),
            'digests' => array_intersect_key($data['digests'] ?? [], array_flip(AlertKinds::DIGESTS)),
        ]);

        return ApiResponse::success($this->present($request));
    }

    /** @return array<string,mixed> */
    private function present(Request $request): array
    {
        $user = $request->user()->fresh();

        return NotificationPrefs::for($user) + [
            'categories' => AlertKinds::CATEGORIES,
            'modes' => AlertKinds::MODES,
            'digest_kinds' => AlertKinds::DIGESTS,
            'email' => $user->email,
            // Honest about the server: with the log mailer nothing leaves it, and the screen says so.
            'mail_configured' => config('mail.default') !== 'log',
        ];
    }
}
