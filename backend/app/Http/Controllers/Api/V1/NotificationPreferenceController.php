<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Mail\MailerRegistry;
use App\Domain\Notifications\AlertKinds;
use App\Domain\Notifications\NotificationPrefs;
use App\Domain\Notifications\StaffWhatsapp;
use App\Http\Controllers\Controller;
use App\Models\StaffAlertLog;
use App\Services\AuditLogger;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** How the signed-in person wants to be told: each kind of alert at once, later, or not at all; and which summaries. */
class NotificationPreferenceController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

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
            // WhatsApp alerts to the person themselves: switched on by them, per category, with their own quiet hours.
            'whatsapp' => ['sometimes', 'array'],
            'whatsapp.enabled' => ['sometimes', 'boolean'],
            'whatsapp.alerts' => ['sometimes', 'array'],
            'whatsapp.alerts.*' => ['string', Rule::in(StaffWhatsapp::MODES)],
            'whatsapp.quiet' => ['sometimes', 'nullable', 'array'],
            'whatsapp.quiet.enabled' => ['required_with:whatsapp.quiet', 'boolean'],
            'whatsapp.quiet.start' => ['sometimes', 'date_format:H:i'],
            'whatsapp.quiet.end' => ['sometimes', 'date_format:H:i'],
        ]);

        // Only keys that exist: an unknown category is ignored, not stored.
        NotificationPrefs::save($request->user(), [
            'alerts' => array_intersect_key($data['alerts'] ?? [], array_flip(AlertKinds::CATEGORIES)),
            'digests' => array_intersect_key($data['digests'] ?? [], array_flip(AlertKinds::DIGESTS)),
        ]);

        if (isset($data['whatsapp'])) {
            $user = $request->user();
            $before = StaffWhatsapp::prefs($user);
            $after = StaffWhatsapp::save($user, array_intersect_key($data['whatsapp'], array_flip(['enabled', 'alerts', 'quiet'])));
            // A person's own switch for being messaged on their phone: on the record, without the number.
            $this->audit->log('notification_prefs.whatsapp_changed', $user, $user, ['before' => $before, 'after' => $after]);
        }

        return ApiResponse::success($this->present($request));
    }

    /** The person's own recent WhatsApp alerts and what became of each — including the ones held back, and why. */
    public function whatsappLog(Request $request)
    {
        $rows = StaffAlertLog::query()->where('user_id', $request->user()->id)->latest('id')->limit(30)->get();

        return ApiResponse::success(['log' => $rows->map(fn (StaffAlertLog $l) => [
            'id' => $l->uuid, 'category' => $l->category, 'title' => $l->title, 'status' => $l->status, 'reason' => $l->reason,
            'provider' => $l->provider, 'error' => $l->error, 'at' => $l->created_at?->toIso8601String(), 'sent_at' => $l->sent_at?->toIso8601String(),
        ])->all()]);
    }

    /** @return array<string,mixed> */
    private function present(Request $request): array
    {
        $user = $request->user()->fresh();
        $door = app(MailerRegistry::class)->current();

        return NotificationPrefs::for($user) + [
            'categories' => AlertKinds::CATEGORIES,
            'modes' => AlertKinds::MODES,
            'digest_kinds' => AlertKinds::DIGESTS,
            'email' => $user->email,
            // Honest about the door: when the product's mail provider only writes it down, or has no keys, nothing leaves, and the screen says so.
            'mail_configured' => $door->delivers() && $door->isConfigured(),
            'whatsapp' => StaffWhatsapp::prefs($user) + [
                'categories' => AlertKinds::STAFF_WHATSAPP,
                'modes' => StaffWhatsapp::MODES,
                'has_phone' => filled($user->phone),
                // Whether the workspace has a WhatsApp number that can send at all; the mock is "available" and says so.
                'available' => app(WhatsappManager::class)->canSend(app(WhatsappManager::class)->integration()),
                'live' => app(WhatsappManager::class)->isLive(app(WhatsappManager::class)->integration()),
            ],
        ];
    }
}
