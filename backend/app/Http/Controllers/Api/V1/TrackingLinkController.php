<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\LinkStats;
use App\Domain\Tracking\TrackingLinks;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrackingLinkResource;
use App\Models\Ad;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Partner;
use App\Models\Project;
use App\Models\Property;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The links a workspace hands out, and what each has done.
 *
 * Reading is for everyone who can see the workspace; making and changing a link is a manager's
 * call, because a link is a promise about where credit goes — change a campaign on a link that is
 * already printed on a billboard and every later lead is attributed to the wrong thing.
 */
class TrackingLinkController extends Controller
{
    private const WITH = ['partner:id,uuid,name,type', 'project:id,uuid,name', 'property:id,uuid,code', 'campaign:id,uuid,name', 'ad:id,uuid,name', 'creative:id,uuid,name', 'rep:id,uuid,name'];

    public function __construct(private TrackingLinks $links, private LinkStats $stats, private AuditLogger $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', TrackingLink::class);

        $f = $request->validate([
            'channel' => ['nullable', Rule::in(TrackingLink::CHANNELS)],
            'kind' => ['nullable', Rule::in(TrackingLink::KINDS)],
            'project_id' => ['nullable', 'uuid'],
            'active' => ['nullable', 'boolean'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $links = TrackingLink::query()->with(self::WITH)
            ->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when($f['project_id'] ?? null, fn ($q, $uuid) => $q->whereIn('project_id', Project::where('uuid', $uuid)->select('id')))
            ->when(isset($f['active']), fn ($q) => $q->where('is_active', (bool) $f['active']))
            ->latest('id')->limit(300)->get();

        $stats = $this->stats->for($links->pluck('id')->all(), isset($f['days']) ? now()->subDays((int) $f['days'])->startOfDay() : null);

        return ApiResponse::success([
            'links' => $links->map(fn (TrackingLink $l) => (new TrackingLinkResource($l))->resolve() + ['stats' => $stats[$l->id]])->values(),
            'channels' => TrackingLink::CHANNELS,
        ]);
    }

    public function show(string $uuid)
    {
        $link = TrackingLink::where('uuid', $uuid)->with(self::WITH)->firstOrFail();
        $this->authorize('view', $link);

        $recent = TrackingEvent::where('tracking_link_id', $link->id)->latest('occurred_at')->latest('id')->limit(25)->get()
            ->map(fn (TrackingEvent $e) => [
                'type' => $e->event_type, 'at' => $e->occurred_at->toIso8601String(), 'device' => $e->device, 'browser' => $e->browser,
                'referrer' => $e->referrer, 'lead_id' => $e->lead_id ? $e->lead()->withoutGlobalScopes()->value('uuid') : null,
            ]);

        return ApiResponse::success([
            'link' => (new TrackingLinkResource($link))->resolve() + ['stats' => $this->stats->for([$link->id])[$link->id]],
            'recent' => $recent,
            // The two things a marketer copies: the link itself, and the script for a landing page.
            'embed' => [
                'link' => $link->url(),
                'script' => '<script src="'.rtrim((string) config('app.url'), '/').'/tracker/'.$link->token.'.js" defer></script>',
            ],
        ]);
    }

    /**
     * The link as a QR code, drawn as SVG: it prints at any size without going soft, and needs no
     * image library on the server. What it encodes is the short public link, so a code on a
     * billboard keeps working however the destination is edited later.
     */
    public function qr(Request $request, string $uuid)
    {
        $link = TrackingLink::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $link);

        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(320, 2),
            new SvgImageBackEnd,
        )))->writeString($link->url());

        return response($svg, 200, array_filter([
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => $request->boolean('download') ? 'attachment; filename="qr-'.Str::slug($link->name ?: $link->token).'.svg"' : null,
        ]));
    }

    public function store(Request $request)
    {
        $this->authorize('create', TrackingLink::class);

        $link = $this->links->create($this->validated($request), $request->user()->id);
        $this->audit->log('tracking_link.created', $request->user(), $link, ['after' => ['name' => $link->name, 'channel' => $link->channel, 'token' => $link->token]]);

        return ApiResponse::created(['link' => new TrackingLinkResource($link->load(self::WITH))]);
    }

    public function update(Request $request, string $uuid)
    {
        $link = TrackingLink::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $link);

        $before = $link->only(['name', 'channel', 'project_id', 'campaign_id', 'ad_id', 'is_active', 'destination_url']);
        $link->update($this->validated($request, partial: true));
        $this->audit->log('tracking_link.updated', $request->user(), $link, ['before' => $before, 'after' => $link->only(array_keys($before))]);

        return ApiResponse::success(['link' => new TrackingLinkResource($link->refresh()->load(self::WITH))]);
    }

    public function destroy(Request $request, string $uuid)
    {
        $link = TrackingLink::where('uuid', $uuid)->firstOrFail();
        $this->authorize('delete', $link);

        $this->audit->log('tracking_link.deleted', $request->user(), $link, ['before' => ['name' => $link->name, 'token' => $link->token]]);
        // Soft-deleted: the events it produced and the leads it brought keep their credit.
        $link->delete();

        return ApiResponse::success(null, 'Link deleted.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(TrackingLink::KINDS)],
            'channel' => ['sometimes', Rule::in(TrackingLink::CHANNELS)],
            'project_id' => ['nullable', 'uuid'],
            'property_id' => ['nullable', 'uuid'],
            'campaign_id' => ['nullable', 'uuid'],
            'ad_id' => ['nullable', 'uuid'],
            'creative_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'partner_id' => ['nullable', 'uuid'],
            'placement' => ['nullable', Rule::in(TrackingLink::PLACEMENTS)],
            'landing_page' => ['nullable', 'string', 'max:160'],
            'branch' => ['nullable', 'string', 'max:120'],
            'destination_url' => ['nullable', 'string', 'max:2000', 'regex:#^(https?://|tel:|mailto:)#i'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        // uuid → id, each looked up inside the workspace: another workspace's id is simply not there.
        foreach (['project_id' => Project::class, 'property_id' => Property::class, 'campaign_id' => Campaign::class, 'ad_id' => Ad::class, 'creative_id' => Creative::class, 'user_id' => User::class, 'partner_id' => Partner::class] as $key => $model) {
            if (array_key_exists($key, $data)) {
                $data[$key] = blank($data[$key]) ? null : $model::where('uuid', $data[$key])->value('id');
                abort_if($data[$key] === null && filled($request->input($key)), 404, 'Resource not found.');
            }
        }

        if (isset($data['expires_at'])) {
            $data['expires_at'] = Carbon::parse($data['expires_at']);
        }

        return $data;
    }
}
