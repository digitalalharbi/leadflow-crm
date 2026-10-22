<?php

namespace App\Domain\Tracking;

use App\Domain\Campaigns\AdPlatformRegistry;
use App\Domain\Conversions\ConversionHealth;
use App\Enums\IntegrationProvider;
use App\Models\AdConnection;
use App\Models\ConversionExport;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\LeadSourceEvent;
use App\Models\TrackingEvent;
use App\Models\TrackingLink;
use App\Models\WebhookLog;
use App\Models\WhatsappEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Is tracking actually working — per platform, in numbers a manager can act on.
 *
 * A screen of green "connected" badges proves nothing: a form stops posting, a pixel is removed,
 * Meta stops being told about sales, and the spend carries on. So every platform is judged on what
 * it delivered and what became of it — events received, when the last one came, how many were
 * refused or repeated, how many leads cannot be traced to a campaign, how many lack UTM — and on
 * whether sales are reaching it. What is wrong is then said in a sentence with a number in it.
 */
class TrackingHealth
{
    /** The platforms the centre watches, in the order they are shown. */
    public const PLATFORMS = ['meta', 'tiktok', 'snapchat', 'google_ads', 'x', 'linkedin', 'whatsapp', 'landing_page'];

    /** Lead.platform values that belong to each health platform. */
    private const LEAD_PLATFORMS = [
        'meta' => ['meta', 'facebook', 'instagram'], 'tiktok' => ['tiktok'], 'snapchat' => ['snapchat'], 'google_ads' => ['google_ads'],
        'x' => ['x'], 'linkedin' => ['linkedin'], 'whatsapp' => ['whatsapp'], 'landing_page' => ['landing_page', 'website'],
    ];

    /** A tracking link's channel, as the platform it reports under. */
    private const CHANNEL_PLATFORM = ['meta' => 'meta', 'tiktok' => 'tiktok', 'snapchat' => 'snapchat', 'google' => 'google_ads', 'x' => 'x', 'linkedin' => 'linkedin', 'whatsapp' => 'whatsapp', 'landing_page' => 'landing_page'];

    public function __construct(private AdPlatformRegistry $registry, private ConversionHealth $conversions) {}

    /** @return array<string,mixed> */
    public function build(int $days = 7): array
    {
        $since = now()->subDays($days)->startOfDay();
        $conversion = collect($this->conversions->summary())->keyBy('platform');

        $arrived = $this->arrived($since);
        $tracked = $this->tracked($since);
        $refused = $this->refused($since);
        $leads = $this->leadQuality($since);
        $won = $this->wonExports();
        $trend = $this->trend($days);

        $rows = collect(self::PLATFORMS)->map(function (string $p) use ($arrived, $tracked, $refused, $leads, $won, $conversion, $trend, $days) {
            $a = $arrived[$p] ?? ['accepted' => 0, 'duplicates' => 0, 'last' => null];
            $t = $tracked[$p] ?? ['events' => 0, 'last' => null];
            $r = $refused[$p] ?? 0;
            $l = $leads[$p] ?? ['leads' => 0, 'missing_utm' => 0, 'unattributed' => 0];
            $c = $conversion->get($p);

            $events = $a['accepted'] + $a['duplicates'] + $t['events'];
            $last = collect([$a['last'], $t['last']])->filter()->max();
            $connected = $this->connected($p);
            $attempts = $a['accepted'] + $r;

            $row = [
                'platform' => $p,
                'connected' => $connected,
                'receiving' => $events > 0 && $last !== null && Carbon::parse($last)->gte(now()->subDays(2)),
                'events' => $events,
                'accepted' => $a['accepted'],
                'last_event_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
                'success_rate' => $attempts > 0 ? round($a['accepted'] / $attempts * 100, 1) : null,
                'rejected' => $r,
                'duplicates' => $a['duplicates'],
                'duplicate_rate' => ($a['accepted'] + $a['duplicates']) > 0 ? round($a['duplicates'] / ($a['accepted'] + $a['duplicates']) * 100, 1) : null,
                'leads' => $l['leads'],
                'missing_utm' => $l['missing_utm'],
                'missing_utm_percent' => $l['leads'] > 0 ? round($l['missing_utm'] / $l['leads'] * 100, 1) : null,
                'unattributed' => $l['unattributed'],
                'unattributed_percent' => $l['leads'] > 0 ? round($l['unattributed'] / $l['leads'] * 100, 1) : null,
                'offline' => $c === null ? null : [
                    'state' => $c['state'], 'configured' => $c['configured'], 'missing' => $c['missing'], 'sent' => (int) ($c['counts'][ConversionExport::SENT] ?? 0),
                    'failed' => (int) ($c['counts'][ConversionExport::FAILED] ?? 0), 'last_sent_at' => $c['last_sent_at'], 'last_error' => $c['last_error'],
                    'won_last_sent_at' => $won['sent'][$p] ?? null, 'won_waiting' => $won['recent_wins'][$p] ?? 0,
                ],
                'trend' => $trend[$p] ?? array_fill(0, $days, 0),
            ];
            $row['status'] = $this->status($row);

            return $row;
        });

        $withLeads = $rows->sum('leads');

        return [
            'days' => $days,
            'summary' => [
                'connected' => $rows->where('connected', true)->count(),
                'receiving' => $rows->where('receiving', true)->count(),
                'at_risk' => $rows->where('status', 'risk')->count(),
                'platforms' => $rows->count(),
                'unattributed_percent' => $this->unattributedOverall($since),
                'missing_utm_percent' => $withLeads > 0 ? round($rows->sum('missing_utm') / $withLeads * 100, 1) : null,
                'links_without_utm_percent' => $this->linksWithoutUtm(),
            ],
            'platforms' => $rows->all(),
            'recommendations' => $this->recommendations($rows, $days),
        ];
    }

    // ------------------------------------------------------------ the facts

    /** Leads delivered by each platform's source, and how many of them were repeats. @return array<string,array{accepted:int,duplicates:int,last:?string}> */
    private function arrived(Carbon $since): array
    {
        $out = [];
        LeadSourceEvent::query()->where('received_at', '>=', $since)
            ->selectRaw('platform, count(*) filter (where not was_duplicate) as accepted, count(*) filter (where was_duplicate) as duplicates, max(received_at) as last')
            ->groupBy('platform')->get()
            ->each(function ($row) use (&$out) {
                $p = $this->platformOf((string) $row->platform);
                if ($p === null) {
                    return;
                }
                $out[$p] ??= ['accepted' => 0, 'duplicates' => 0, 'last' => null];
                $out[$p]['accepted'] += (int) $row->accepted;
                $out[$p]['duplicates'] += (int) $row->duplicates;
                $out[$p]['last'] = collect([$out[$p]['last'], $row->last])->filter()->max();
            });

        // WhatsApp's own deliveries: a customer writing in is the event.
        $wa = LeadMessage::query()->where('direction', 'inbound')->where('created_at', '>=', $since)->selectRaw('count(*) as n, max(created_at) as last')->first();
        if ($wa && (int) $wa->n > 0) {
            $out['whatsapp'] = ['accepted' => (int) $wa->n, 'duplicates' => 0, 'last' => $wa->last];
        }

        return $out;
    }

    /** What the tracking links reported, by the platform each link belongs to. @return array<string,array{events:int,last:?string}> */
    private function tracked(Carbon $since): array
    {
        $links = TrackingLink::withTrashed()->pluck('channel', 'id');
        $out = [];

        TrackingEvent::query()->where('occurred_at', '>=', $since)->whereNotNull('tracking_link_id')
            ->selectRaw('tracking_link_id, count(*) as n, max(occurred_at) as last')->groupBy('tracking_link_id')->get()
            ->each(function ($row) use ($links, &$out) {
                $p = self::CHANNEL_PLATFORM[$links[$row->tracking_link_id] ?? ''] ?? null;
                if ($p === null) {
                    return;
                }
                $out[$p] ??= ['events' => 0, 'last' => null];
                $out[$p]['events'] += (int) $row->n;
                $out[$p]['last'] = collect([$out[$p]['last'], $row->last])->filter()->max();
            });

        return $out;
    }

    /** Deliveries the product refused: bad secrets, broken payloads, a WhatsApp signature that did not verify. @return array<string,int> */
    private function refused(Carbon $since): array
    {
        $out = [];

        WebhookLog::query()->where('received_at', '>=', $since)->whereIn('status', ['rejected', 'failed'])
            ->selectRaw('provider, count(*) as n')->groupBy('provider')->get()
            ->each(function ($row) use (&$out) {
                $platform = IntegrationProvider::tryFrom((string) $row->provider)?->defaultPlatform()->value;
                $p = $this->platformOf((string) $platform) ?? 'landing_page';
                $out[$p] = ($out[$p] ?? 0) + (int) $row->n;
            });

        $wa = WhatsappEvent::query()->where('event_type', WhatsappEvent::TYPE_REJECTED)->where('created_at', '>=', $since)->sum('occurrences');
        if ($wa > 0) {
            $out['whatsapp'] = ($out['whatsapp'] ?? 0) + (int) $wa;
        }

        return $out;
    }

    /** Per platform: how many of the period's leads lack UTM and a campaign. @return array<string,array{leads:int,missing_utm:int,unattributed:int}> */
    private function leadQuality(Carbon $since): array
    {
        $out = [];

        Lead::query()->reorder()->where('created_at', '>=', $since)->whereNotNull('platform')
            ->selectRaw("platform, count(*) as leads,
                count(*) filter (where coalesce(utm_source,'') = '' and coalesce(utm_campaign,'') = '') as missing_utm,
                count(*) filter (where campaign_ref_id is null and first_touch_link_id is null and coalesce(campaign_name,'') = '') as unattributed")
            ->groupBy('platform')->get()
            ->each(function ($row) use (&$out) {
                $p = $this->platformOf((string) $row->platform);
                if ($p === null) {
                    return;
                }
                $out[$p] ??= ['leads' => 0, 'missing_utm' => 0, 'unattributed' => 0];
                $out[$p]['leads'] += (int) $row->leads;
                $out[$p]['missing_utm'] += (int) $row->missing_utm;
                $out[$p]['unattributed'] += (int) $row->unattributed;
            });

        return $out;
    }

    /** Sales waiting to be told to each platform, and when one last got through. @return array{sent:array<string,?string>,recent_wins:array<string,int>} */
    private function wonExports(): array
    {
        $sent = ConversionExport::query()->where('event', 'won')->where('status', ConversionExport::SENT)->selectRaw('platform, max(sent_at) as at')->groupBy('platform')->pluck('at', 'platform')
            ->map(fn ($at) => $at ? Carbon::parse($at)->toIso8601String() : null)->all();

        $wins = [];
        Lead::query()->reorder()->where('status', 'won')->where('won_at', '>=', now()->subHours(48))->whereNotNull('platform')
            ->selectRaw('platform, count(*) as n')->groupBy('platform')->get()
            ->each(function ($row) use (&$wins) {
                $p = $this->platformOf((string) $row->platform);
                if ($p !== null) {
                    $wins[$p] = ($wins[$p] ?? 0) + (int) $row->n;
                }
            });

        return ['sent' => $sent, 'recent_wins' => $wins];
    }

    /** Daily event counts per platform, oldest first, every day present. @return array<string,array<int,int>> */
    private function trend(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $dates = collect(range($days - 1, 0))->map(fn (int $ago) => now()->subDays($ago)->toDateString());
        $counts = [];

        LeadSourceEvent::query()->where('received_at', '>=', $from)->selectRaw('platform, date(received_at) as d, count(*) as n')->groupBy('platform', 'd')->get()
            ->each(function ($r) use (&$counts) {
                $p = $this->platformOf((string) $r->platform);
                $p && $counts[$p][$r->d] = ($counts[$p][$r->d] ?? 0) + (int) $r->n;
            });

        $links = TrackingLink::withTrashed()->pluck('channel', 'id');
        TrackingEvent::query()->where('occurred_at', '>=', $from)->whereNotNull('tracking_link_id')->selectRaw('tracking_link_id, date(occurred_at) as d, count(*) as n')->groupBy('tracking_link_id', 'd')->get()
            ->each(function ($r) use (&$counts, $links) {
                $p = self::CHANNEL_PLATFORM[$links[$r->tracking_link_id] ?? ''] ?? null;
                $p && $counts[$p][$r->d] = ($counts[$p][$r->d] ?? 0) + (int) $r->n;
            });

        return collect($counts)->map(fn (array $byDay) => $dates->map(fn (string $d) => (int) ($byDay[$d] ?? 0))->all())->all();
    }

    private function connected(string $platform): bool
    {
        return match ($platform) {
            'whatsapp' => Integration::query()->where('provider', 'whatsapp')->where('status', Integration::CONNECTED)->exists(),
            // A landing page is "connected" once a live link for it exists: that is what the script is served from.
            'landing_page' => TrackingLink::query()->where('channel', 'landing_page')->where('is_active', true)->exists(),
            default => $this->registry->get($platform)->isConfigured(AdConnection::for($platform)),
        };
    }

    private function platformOf(string $platform): ?string
    {
        foreach (self::LEAD_PLATFORMS as $health => $values) {
            if (in_array($platform, $values, true)) {
                return $health;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ judging

    /**
     * One word for the card.
     *
     *   idle       — not connected and nothing arriving: nothing to judge
     *   risk       — connected but silent for two days, refusing a fifth or more of what it gets,
     *                or sales are waiting that the platform has not been told about
     *   attention  — receiving, but a quarter of its leads cannot be traced, or a third are repeats
     *   healthy    — receiving, and none of the above
     *
     * @param  array<string,mixed>  $row
     */
    private function status(array $row): string
    {
        if (! $row['connected'] && $row['events'] === 0) {
            return 'idle';
        }

        $offline = $row['offline'];
        $winsWaiting = $offline !== null && ($offline['won_waiting'] ?? 0) > 0 && $offline['configured']
            && ($offline['won_last_sent_at'] === null || Carbon::parse($offline['won_last_sent_at'])->lt(now()->subHours(48)));

        if (! $row['receiving'] || ($row['success_rate'] !== null && $row['success_rate'] < 80) || $winsWaiting) {
            return 'risk';
        }

        if (($row['unattributed_percent'] ?? 0) >= 25 || ($row['duplicate_rate'] ?? 0) >= 33) {
            return 'attention';
        }

        return 'healthy';
    }

    private function unattributedOverall(Carbon $since): ?float
    {
        $r = Lead::query()->reorder()->where('created_at', '>=', $since)
            ->selectRaw("count(*) as n, count(*) filter (where campaign_ref_id is null and first_touch_link_id is null and coalesce(campaign_name,'') = '') as u")->first();

        return (int) $r->n > 0 ? round((int) $r->u / (int) $r->n * 100, 1) : null;
    }

    /** Share of live links that carry no UTM at all. */
    private function linksWithoutUtm(): ?float
    {
        $live = TrackingLink::query()->where('is_active', true)->selectRaw("count(*) as n, count(*) filter (where coalesce(utm_source,'') = '' and coalesce(utm_campaign,'') = '' and coalesce(utm_medium,'') = '') as u")->first();

        return (int) $live->n > 0 ? round((int) $live->u / (int) $live->n * 100, 1) : null;
    }

    /**
     * What to do about it, in sentences with the number in them. Deterministic, ordered by how much
     * it is costing, and short: five at most.
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function recommendations(Collection $rows, int $days): array
    {
        $out = [];
        $overall = $this->unattributedOverall(now()->subDays($days)->startOfDay());

        foreach ($rows as $r) {
            $offline = $r['offline'] ?? null;

            if ($offline && $offline['configured'] && ($offline['won_waiting'] ?? 0) > 0 && ($offline['won_last_sent_at'] === null || Carbon::parse($offline['won_last_sent_at'])->lt(now()->subHours(48)))) {
                $out[] = ['key' => 'won_not_sent', 'severity' => 'critical', 'platform' => $r['platform'], 'params' => ['hours' => 48, 'count' => $offline['won_waiting']], 'to' => 'health'];
            }

            if ($r['connected'] && $r['events'] > 0 && ! $r['receiving']) {
                $out[] = ['key' => 'platform_silent', 'severity' => 'critical', 'platform' => $r['platform'], 'params' => ['hours' => 48], 'to' => 'health'];
            }

            if ($r['success_rate'] !== null && $r['success_rate'] < 80) {
                $out[] = ['key' => 'rejecting', 'severity' => 'critical', 'platform' => $r['platform'], 'params' => ['percent' => round(100 - $r['success_rate'], 1)], 'to' => 'health'];
            }

            if (($r['duplicate_rate'] ?? 0) >= 33) {
                $out[] = ['key' => 'duplicates', 'severity' => 'warning', 'platform' => $r['platform'], 'params' => ['percent' => $r['duplicate_rate']], 'to' => 'health'];
            }
        }

        if ($overall !== null && $overall >= 10) {
            $out[] = ['key' => 'unattributed', 'severity' => $overall >= 30 ? 'critical' : 'warning', 'platform' => null, 'params' => ['percent' => $overall], 'to' => 'attribution'];
        }

        $links = $this->linksWithoutUtm();
        if ($links !== null && $links >= 10) {
            $out[] = ['key' => 'links_without_utm', 'severity' => 'warning', 'platform' => null, 'params' => ['percent' => $links], 'to' => 'links'];
        }

        $leads = $rows->sum('leads');
        $missing = $rows->sum('missing_utm');
        if ($leads > 0 && $missing / $leads >= 0.2) {
            $out[] = ['key' => 'leads_without_utm', 'severity' => 'warning', 'platform' => null, 'params' => ['percent' => round($missing / $leads * 100, 1)], 'to' => 'links'];
        }

        if ($leads > 0 && ! TrackingLink::query()->exists()) {
            $out[] = ['key' => 'no_links', 'severity' => 'warning', 'platform' => null, 'params' => [], 'to' => 'links'];
        }

        $rank = ['critical' => 0, 'warning' => 1];

        return collect($out)->sortBy(fn (array $o) => $rank[$o['severity']] ?? 2)->take(6)->values()->all();
    }
}
