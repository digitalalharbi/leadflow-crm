<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ReportSchedules;
use App\Http\Controllers\Controller;
use App\Models\ReportSchedule;
use App\Models\ReportShare;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A report that sends itself: daily, weekly or monthly, to a list of people. Managed by whoever may
 * change the report; every change is on the audit trail with the recipients' count, not their names.
 */
class ReportScheduleController extends Controller
{
    private const MAX_PER_REPORT = 5;

    public function __construct(private ReportSchedules $schedules, private AuditLogger $audit) {}

    public function index(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $share);

        return ApiResponse::success(['schedules' => $share->schedules()->oldest('id')->get()->map(fn (ReportSchedule $s) => $this->present($s))->all()]);
    }

    public function store(Request $request, string $uuid)
    {
        $share = ReportShare::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $share);
        abort_if($share->schedules()->count() >= self::MAX_PER_REPORT, 422, 'A report can have up to '.self::MAX_PER_REPORT.' schedules.');

        $data = $this->validated($request, false);

        $schedule = new ReportSchedule(['report_share_id' => $share->id, 'created_by' => $request->user()->id] + $this->fields($data) + ['enabled' => $data['enabled'] ?? true]);
        $schedule->save();
        $this->schedules->arm($schedule);

        $this->audit->log('report_share.schedule_created', $request->user(), $share, ['schedule' => $schedule->frequency, 'recipients' => count($schedule->recipients)]);

        return ApiResponse::created(['schedule' => $this->present($schedule->fresh())]);
    }

    public function update(Request $request, string $uuid)
    {
        $schedule = ReportSchedule::where('uuid', $uuid)->with('share')->firstOrFail();
        $this->authorize('update', $schedule->share);

        $data = $this->validated($request, true);
        $schedule->fill($this->fields($data));

        if (array_key_exists('enabled', $data)) {
            $schedule->enabled = (bool) $data['enabled'];
        }

        // Turning a stopped schedule back on is only worth anything while its report is open.
        if ($schedule->enabled && $schedule->share->status() !== 'active') {
            abort(422, 'The report is closed: reopen it before turning its schedule on.');
        }

        $schedule->save();
        $this->schedules->arm($schedule);
        $this->audit->log('report_share.schedule_updated', $request->user(), $schedule->share, ['schedule' => $schedule->frequency, 'enabled' => $schedule->enabled, 'recipients' => count($schedule->recipients)]);

        return ApiResponse::success(['schedule' => $this->present($schedule->fresh())]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $schedule = ReportSchedule::where('uuid', $uuid)->with('share')->firstOrFail();
        $this->authorize('update', $schedule->share);

        $this->audit->log('report_share.schedule_deleted', $request->user(), $schedule->share, ['schedule' => $schedule->frequency]);
        $schedule->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'frequency' => [$req, Rule::in(ReportSchedule::FREQUENCIES)],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            'month_day' => ['nullable', 'integer', 'between:1,28'],
            'send_hour' => ['sometimes', 'integer', 'between:0,23'],
            'recipients' => [$req, 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email:rfc', 'max:190'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (($data['frequency'] ?? null) === 'weekly' && empty($data['weekday'])) {
            abort(422, 'A weekly report needs a weekday.');
        }

        if (($data['frequency'] ?? null) === 'monthly' && empty($data['month_day'])) {
            abort(422, 'A monthly report needs a day of the month (1–28).');
        }

        return $data;
    }

    /** @param  array<string,mixed>  $data @return array<string,mixed> */
    private function fields(array $data): array
    {
        $out = array_intersect_key($data, array_flip(['frequency', 'weekday', 'month_day', 'send_hour', 'message']));

        if (isset($data['recipients'])) {
            $out['recipients'] = array_values(array_unique(array_map('strtolower', $data['recipients'])));
        }

        // Only the field the frequency uses is kept.
        if (($out['frequency'] ?? null) === 'daily') {
            $out['weekday'] = null;
            $out['month_day'] = null;
        } elseif (($out['frequency'] ?? null) === 'weekly') {
            $out['month_day'] = null;
        } elseif (($out['frequency'] ?? null) === 'monthly') {
            $out['weekday'] = null;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function present(ReportSchedule $s): array
    {
        return [
            'id' => $s->uuid, 'frequency' => $s->frequency, 'weekday' => $s->weekday, 'month_day' => $s->month_day, 'send_hour' => $s->send_hour,
            'recipients' => $s->recipients, 'message' => $s->message, 'enabled' => $s->enabled,
            'next_run_at' => $s->next_run_at?->toIso8601String(), 'last_run_at' => $s->last_run_at?->toIso8601String(), 'last_status' => $s->last_status,
        ];
    }
}
