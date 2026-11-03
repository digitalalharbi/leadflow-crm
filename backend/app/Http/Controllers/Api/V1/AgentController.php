<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\AgentConversation;
use App\Domain\Agents\AgentDirectory;
use App\Domain\Agents\Brain\BrainFactory;
use App\Domain\Agents\Orchestrator;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentReport;
use App\Models\AgentThread;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Routine;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** The agents, their routines, and what they last said. */
class AgentController extends Controller
{
    public function index(Request $request, AgentDirectory $directory)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);
        $directory->ensure();

        $latest = AgentThread::query()->where('routine', 'daily_analysis')->latest('id')->with('messages')->first();
        $findings = collect($latest?->messages ?? [])->firstWhere('agent', 'analyst')?->data['findings'] ?? [];

        return ApiResponse::success([
            'agents' => Agent::query()->orderBy('id')->get(['key', 'name', 'role', 'is_active']),
            'routines' => Routine::query()->orderBy('id')->get()->map(fn (Routine $r) => [
                'key' => $r->key,
                'agent' => $r->agent,
                'cron' => $r->cron,
                'is_active' => $r->is_active,
                'last_run_at' => $r->last_run_at?->toIso8601String(),
                'last_status' => $r->last_status,
                'last_summary' => $r->last_summary,
            ]),
            'findings' => $findings,
            'analysed_at' => $latest?->created_at?->toIso8601String(),
            'threads' => AgentThread::query()->latest('id')->limit(10)->get()->map(fn (AgentThread $t) => [
                'id' => $t->uuid, 'routine' => $t->routine, 'agent' => $t->agent, 'created_at' => $t->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function run(Request $request, Orchestrator $orchestrator, string $routine)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);
        abort_unless(array_key_exists($routine, AgentDirectory::ROUTINES), 404);

        $thread = $orchestrator->run($routine, $request->user());

        return ApiResponse::success(['thread' => $this->present($thread->load('messages'))]);
    }

    public function thread(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);

        return ApiResponse::success(['thread' => $this->present(AgentThread::query()->where('uuid', $uuid)->with('messages')->firstOrFail())]);
    }

    public function reports(Request $request)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);

        return ApiResponse::success([
            'reports' => AgentReport::query()->latest('id')->limit(30)->get()->map(fn (AgentReport $r) => [
                'id' => $r->uuid, 'kind' => $r->kind, 'period_from' => $r->period_from->toDateString(), 'period_to' => $r->period_to->toDateString(),
                'created_at' => $r->created_at?->toIso8601String(), 'totals' => $r->data['totals'] ?? null,
            ]),
        ]);
    }

    public function report(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);
        $r = AgentReport::query()->where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success(['report' => [
            'id' => $r->uuid, 'kind' => $r->kind, 'period_from' => $r->period_from->toDateString(), 'period_to' => $r->period_to->toDateString(),
            'created_at' => $r->created_at?->toIso8601String(), 'data' => $r->data,
        ]]);
    }

    // ------------------------------------------------------------- conversation

    public function conversations(Request $request)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);

        return ApiResponse::success(['threads' => AgentThread::query()
            ->whereNull('routine')->where('started_by', $request->user()->id)
            ->latest('id')->limit(30)->get()
            ->map(fn (AgentThread $t) => ['id' => $t->uuid, 'agent' => $t->agent, 'title' => $t->title, 'created_at' => $t->created_at?->toIso8601String()])]);
    }

    public function start(Request $request, AgentConversation $conversation)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000'], 'agent' => ['nullable', 'in:'.implode(',', AgentConversation::AGENTS)]]);

        $thread = AgentThread::create([
            'agent' => $data['agent'] ?? 'orchestrator',
            'started_by' => $request->user()->id,
            'title' => mb_substr($data['message'], 0, 120),
        ]);

        $conversation->reply($thread, $request->user(), $data['message'], $data['agent'] ?? null);

        return ApiResponse::created(['thread' => $this->present($thread->load('messages'))]);
    }

    public function say(Request $request, AgentConversation $conversation, string $uuid)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited($request);
        $data = $request->validate(['message' => ['required', 'string', 'max:4000'], 'agent' => ['nullable', 'in:'.implode(',', AgentConversation::AGENTS)]]);

        $thread = AgentThread::query()->where('uuid', $uuid)->where('started_by', $request->user()->id)->firstOrFail();
        $conversation->reply($thread, $request->user(), $data['message'], $data['agent'] ?? null);

        return ApiResponse::success(['thread' => $this->present($thread->load('messages'))]);
    }

    /** Whether the agents can think, and why not — never a silent fallback. */
    public function ai(BrainFactory $brains)
    {
        $this->authorize('export', Lead::class);
        $this->refuseProjectLimited(request());
        $settings = app(TenantContext::class)->get()?->settings;

        return ApiResponse::success([
            'status' => $brains->make()->status(),
            'source' => filled($settings?->ai_api_key) ? 'workspace' : (filled(config('services.anthropic.key')) ? 'platform' : null),
            'verified_at' => $settings?->ai_verified_at?->toIso8601String(),
            'last_error' => $settings?->ai_last_error,
            'models' => config('services.anthropic.models'),
        ]);
    }

    public function setKey(Request $request, BrainFactory $brains)
    {
        $this->authorize('viewAny', Integration::class);
        $data = $request->validate(['api_key' => ['required', 'string', 'min:20', 'max:300']]);
        $settings = app(TenantContext::class)->get()->settings;
        $settings->forceFill(['ai_api_key' => $data['api_key'], 'ai_verified_at' => null, 'ai_last_error' => null])->save();

        // The cheapest call that proves the key: one short turn, no tools.
        try {
            $brains->make()->think('leads', [['type' => 'text', 'text' => 'Reply with OK.']], [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'ping']]]], []);
            $settings->forceFill(['ai_verified_at' => now()])->save();
        } catch (\Throwable $e) {
            $settings->forceFill(['ai_last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }

        return $this->ai($brains);
    }

    public function forgetKey(BrainFactory $brains)
    {
        $this->authorize('viewAny', Integration::class);
        app(TenantContext::class)->get()->settings->forceFill(['ai_api_key' => null, 'ai_verified_at' => null, 'ai_last_error' => null])->save();

        return $this->ai($brains);
    }

    private function present(AgentThread $t): array
    {
        return [
            'id' => $t->uuid,
            'routine' => $t->routine,
            'created_at' => $t->created_at?->toIso8601String(),
            'agent' => $t->agent,
            'title' => $t->title,
            'messages' => $t->messages->map(fn ($m) => ['role' => $m->role, 'agent' => $m->agent, 'content' => $m->content, 'data' => $m->data, 'at' => $m->created_at?->toIso8601String()])->all(),
        ];
    }

    /** These read the whole company: the routines are not built per project, so someone limited to some projects does not get them. */
    private function refuseProjectLimited(Request $request): void
    {
        abort_if($request->user()->isProjectScoped(), 403, 'Your role does not include this.');
    }
}
