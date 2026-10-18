<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\AgentDirectory;
use App\Domain\Agents\Orchestrator;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentThread;
use App\Models\Lead;
use App\Models\Routine;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** The agents, their routines, and what they last said. */
class AgentController extends Controller
{
    public function index(Request $request, AgentDirectory $directory)
    {
        $this->authorize('export', Lead::class);
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
        abort_unless(array_key_exists($routine, AgentDirectory::ROUTINES), 404);

        $thread = $orchestrator->run($routine, $request->user());

        return ApiResponse::success(['thread' => $this->present($thread->load('messages'))]);
    }

    public function thread(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);

        return ApiResponse::success(['thread' => $this->present(AgentThread::query()->where('uuid', $uuid)->with('messages')->firstOrFail())]);
    }

    private function present(AgentThread $t): array
    {
        return [
            'id' => $t->uuid,
            'routine' => $t->routine,
            'created_at' => $t->created_at?->toIso8601String(),
            'messages' => $t->messages->map(fn ($m) => ['role' => $m->role, 'agent' => $m->agent, 'content' => $m->content, 'data' => $m->data, 'at' => $m->created_at?->toIso8601String()])->all(),
        ];
    }
}
