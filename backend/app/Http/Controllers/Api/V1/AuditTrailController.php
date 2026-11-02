<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditTrail;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Who moved that?" — one list of what people and automation changed, newest first. */
class AuditTrailController extends Controller
{
    public function __invoke(Request $request, AuditTrail $trail)
    {
        // The trail is the whole company's: someone limited to some projects does not read it.
        abort_if($request->user()->isProjectScoped(), 403, 'Your role does not include this.');

        abort_unless($request->user()->isAdmin() || $request->user()->isManager() || $request->user()->usesMatrix(), 403);

        $f = $request->validate([
            'category' => ['nullable', Rule::in(AuditTrail::CATEGORIES)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'actor' => ['nullable', 'uuid'],
        ]);

        return ApiResponse::success([
            'entries' => $trail->entries([
                'category' => $f['category'] ?? null,
                'from' => now()->subDays((int) ($f['days'] ?? 30))->startOfDay(),
                'actor' => isset($f['actor']) ? User::where('uuid', $f['actor'])->value('id') : null,
            ]),
            'categories' => AuditTrail::CATEGORIES,
        ]);
    }
}
