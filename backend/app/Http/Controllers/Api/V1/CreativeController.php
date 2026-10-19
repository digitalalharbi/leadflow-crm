<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agents\AgentConversation;
use App\Domain\Agents\Brain\BrainFactory;
use App\Domain\Agents\CreativeDrafts;
use App\Domain\Campaigns\AdPlatform;
use App\Domain\Campaigns\CreativePerformance;
use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\AgentThread;
use App\Models\CreativeDraft;
use App\Models\Lead;
use App\Support\ApiResponse;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Creative operations: every ad with its outcome, and the drafts for it.
 *
 * Writing new copy, a hook or a variation asks the Creative Agent — through the
 * same conversation, so it is audited like any other — when the AI is on; when
 * it is not, the person writes the draft and the screen says why. Changing a
 * live ad (pause, duplicate, replace) is never done here: it is a proposal.
 */
class CreativeController extends Controller
{
    public function index(Request $request, CreativePerformance $performance, TenantContext $context)
    {
        $this->authorize('export', Lead::class);

        $platform = in_array($request->input('platform'), AdPlatform::values(), true) ? $request->input('platform') : null;

        return ApiResponse::success([
            'creatives' => $performance->rows(max(1, min(90, (int) $request->input('days', 30))), $platform),
            'currency' => $context->get()?->settings?->currency ?? 'SAR',
        ]);
    }

    public function drafts(string $uuid)
    {
        $this->authorize('export', Lead::class);
        $ad = Ad::query()->where('uuid', $uuid)->firstOrFail();

        return ApiResponse::success(['drafts' => CreativeDraft::query()->where('ad_id', $ad->id)->latest('id')->limit(20)->get()
            ->map(fn ($d) => CreativeDrafts::present($d))->all()]);
    }

    /** Ask the Creative Agent for drafts. Blocked, plainly, when the AI is off. */
    public function generate(Request $request, string $uuid, BrainFactory $brains, AgentConversation $conversation)
    {
        $this->authorize('export', Lead::class);
        $data = $request->validate(['kind' => ['required', 'in:copy,hook,variation'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $ad = Ad::query()->where('uuid', $uuid)->firstOrFail();

        if ($brains->make()->status()['state'] !== 'ready') {
            return ApiResponse::error('ai_blocked', ['reason' => $brains->make()->status()['reason']], 409);
        }

        $thread = AgentThread::create(['agent' => 'creative', 'started_by' => $request->user()->id, 'title' => 'drafts: '.$ad->name]);
        $ask = sprintf(
            'Write 3 %s options for the ad "%s" (id %s). Use get_campaign to see how it and its siblings perform, then save them with draft_creative. %s',
            ['copy' => 'new copy', 'hook' => 'opening hooks', 'variation' => 'variations'][$data['kind']],
            $ad->name, $ad->uuid, $data['notes'] ?? '',
        );
        $conversation->reply($thread, $request->user(), $ask, 'creative');

        return ApiResponse::success([
            'thread' => $thread->uuid,
            'drafts' => CreativeDraft::query()->where('ad_id', $ad->id)->latest('id')->limit(5)->get()->map(fn ($d) => CreativeDrafts::present($d))->all(),
        ]);
    }

    /** A person writes the draft. */
    public function store(Request $request, string $uuid, CreativeDrafts $drafts)
    {
        $this->authorize('export', Lead::class);
        $data = $request->validate([
            'kind' => ['required', 'in:copy,hook,variation'],
            'items' => ['required', 'array', 'min:1', 'max:6'],
            'items.*' => ['string', 'max:600'],
        ]);

        try {
            $out = $drafts->draft(['ad_id' => $uuid] + $data, $request->user(), 'user');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }

        return ApiResponse::created(['draft' => $out['blocks'][0]['items'][0]]);
    }

    public function updateDraft(Request $request, string $uuid)
    {
        $this->authorize('export', Lead::class);
        $data = $request->validate(['status' => ['required', 'in:draft,used,dismissed']]);
        $draft = CreativeDraft::query()->where('uuid', $uuid)->firstOrFail();
        $draft->update($data);

        return ApiResponse::success(['draft' => CreativeDrafts::present($draft)]);
    }
}
