<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Forms\FormDefinition;
use App\Domain\Tracking\Forms\FormDesign;
use App\Domain\Tracking\Forms\FormSchema;
use App\Domain\Tracking\Forms\LandingPageConfig;
use App\Domain\Tracking\Forms\LandingPages;
use App\Domain\Tracking\Forms\LandingSubmission;
use App\Domain\Tracking\Sessions;
use App\Domain\Tracking\Visitor;
use App\Domain\Tracking\WhatsappReference;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TrackingDestination;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The landing page a tracking link opens, and the form on it. Public and unauthenticated by nature: the visitor
 * holds a token and nothing else, so everything that is not a visitor's own word is the server's.
 *
 * A missing, expired, unpublished or foreign page is one answer — 404 — so a token cannot be probed.
 */
class PublicLandingController extends Controller
{
    public function __construct(
        private LandingPages $pages,
        private FormSchema $schema,
        private Sessions $sessions,
        private EventRecorder $events,
        private LandingSubmission $submission,
        private TenantContext $context,
    ) {}

    public function show(Request $request, string $token)
    {
        $page = $this->pages->resolve($token);
        abort_if($page === null, 404);

        ['link' => $link, 'version' => $version, 'tenant' => $tenant] = $page;

        // The page is the PUBLISHED version, frozen: editing the draft never reaches a campaign already running.
        $def = $version->snapshot;

        return $this->context->withTenant($tenant, function () use ($request, $link, $version, $def) {
            $visitor = Visitor::clean($request->query('mt_vid')) ?? Visitor::newId();
            $agent = Visitor::agent($request->userAgent());
            $clickIds = ClickIds::from($request->query());

            // The view is recorded before the page is shown: it must not depend on a script the visitor may never run.
            $session = $this->sessions->touch($visitor, $request->query('mt_sid'), $link, [
                'click_ids' => $clickIds, 'landing_url' => $request->headers->get('referer'), 'referrer' => $request->headers->get('referer'),
                'ip_hash' => Visitor::ipHash($request->ip()),
            ] + $agent);

            $view = $this->events->record(EventType::Visit, [
                'tracking_link_id' => $link->id, 'visitor_id' => $visitor, 'session_id' => $session->session_id,
                'project_id' => $link->project_id, 'property_id' => $link->property_id, 'campaign_id' => $link->campaign_id,
                'platform' => Attribution::platformOf($link->channel), 'consent' => $session->consent,
                'referrer' => $request->headers->get('referer'), 'click_id' => ClickIds::primary($clickIds),
                'ip_hash' => Visitor::ipHash($request->ip()),
            ] + $agent);

            $fields = $this->schema->normalize($def['fields'] ?? []);

            return response()->json(['success' => true, 'data' => [
                'landing' => [
                    'token' => $link->token,
                    'version' => $version->version,
                    'headline' => $def['headline'],
                    'description' => $def['description'],
                    'benefits' => $def['benefits'] ?? [],
                    'cta_label' => $def['cta_label'],
                    'logo_url' => $def['logo_url'],
                    'image_url' => $def['image_url'],
                    'thank_you' => ['title' => $def['thank_you_title'], 'message' => $def['thank_you_message']],
                    'project' => $link->project_id ? Project::whereKey($link->project_id)->first(['name', 'logo_url'])?->only(['name', 'logo_url']) : null,
                    // Only a page has this; a form on a tracking link carries on as it was.
                    'page' => isset($def['page']) ? LandingPageConfig::publicShape($def['page']) : null,
                    'fields' => $this->schema->publicShape($fields),
                    'layout' => $def['layout'],
                    'design' => $def['design'],
                    'settings' => FormDesign::publicSettings($def['settings']),
                    'steps' => FormDefinition::steps($def),
                    'consent' => ['required' => (bool) $def['consent_required'], 'text' => $def['consent_text'], 'privacy_url' => $def['privacy_url']],
                    // The tags this workspace asked the page to load: a public identifier each, never a credential.
                    'tags' => $this->tags($link->project_id),
                ],
                'tracking' => [
                    'visitor_id' => $visitor,
                    'session_id' => $session->session_id,
                    // The id the page's own pixel may use for the same view, so a platform counts it once.
                    'view_event_id' => $view?->event_id,
                    // Said in a WhatsApp message, so the conversation can be tied back to this visit.
                    'ref' => WhatsappReference::for($session->session_id),
                    // What the page pushes to its dataLayer for the view — the same event id the server recorded.
                    'datalayer' => array_filter([
                        'event' => EventType::Visit->canonical(),
                        'event_id' => $view?->event_id,
                        'project_id' => $link->project_id ? Project::whereKey($link->project_id)->value('uuid') : null,
                        'tracking_link_id' => $link->uuid,
                    ]),
                ],
            ], 'message' => null, 'errors' => null]);
        });
    }

    public function submit(Request $request, string $token)
    {
        $page = $this->pages->resolve($token);
        abort_if($page === null, 404);

        $v = Validator::make($request->all(), [
            'submission_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'max:60'],
            'consent' => ['nullable', 'boolean'],
            'website' => ['nullable', 'string', 'max:500'],
            'visitor_id' => ['nullable', 'string', 'max:40'],
            'session_id' => ['nullable', 'string', 'max:40'],
            'url' => ['nullable', 'string', 'max:2000'],
            'referrer' => ['nullable', 'string', 'max:2000'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'click_ids' => ['nullable', 'array', 'max:12'],
        ]);

        if ($v->fails()) {
            return response()->json(['success' => false, 'data' => null, 'message' => 'Invalid submission.', 'errors' => $v->errors()], 422);
        }

        $in = $v->validated();
        $in['consent'] = filter_var($request->input('consent'), FILTER_VALIDATE_BOOLEAN);

        $result = $this->submission->handle($page['link'], $page['version'], $page['tenant'], $in, [
            'ip' => $request->ip(), 'user_agent' => $request->userAgent(), 'referer' => $request->headers->get('referer'),
        ]);

        return response()->json($result['body'] + ['data' => null], $result['status']);
    }

    /**
     * The page-side tags of this workspace (and project): GA4, Google Ads, Meta, TikTok, Snapchat, LinkedIn, X, a GTM web container.
     * Each is an identifier checked for its shape when it was saved; the page loads them only once the visitor has consented.
     *
     * @return list<array{kind:string, id:string}>
     */
    private function tags(?int $projectId): array
    {
        return TrackingDestination::query()->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))->get()
            ->filter(fn (TrackingDestination $d) => $d->destinationKind()->browser() && $d->destinationKind()->configErrors((array) $d->config) === [])
            ->map(fn (TrackingDestination $d) => ['kind' => $d->kind, 'id' => (string) ((array) $d->config)[$d->destinationKind()->config()[0]], 'submit_event' => ((array) $d->event_map)['form_submitted'] ?? null])
            ->values()->all();
    }
}
