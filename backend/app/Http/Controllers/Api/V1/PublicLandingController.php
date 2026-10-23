<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tracking\Attribution;
use App\Domain\Tracking\ClickIds;
use App\Domain\Tracking\EventRecorder;
use App\Domain\Tracking\EventType;
use App\Domain\Tracking\Forms\FormSchema;
use App\Domain\Tracking\Forms\LandingPages;
use App\Domain\Tracking\Forms\LandingSubmission;
use App\Domain\Tracking\Sessions;
use App\Domain\Tracking\Visitor;
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

        ['link' => $link, 'form' => $form, 'tenant' => $tenant] = $page;

        return $this->context->withTenant($tenant, function () use ($request, $link, $form) {
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

            $fields = $this->schema->normalize($form->fields ?? []);

            return response()->json(['success' => true, 'data' => [
                'landing' => [
                    'token' => $link->token,
                    'headline' => $form->headline,
                    'description' => $form->description,
                    'benefits' => $form->benefits ?? [],
                    'cta_label' => $form->cta_label,
                    'logo_url' => $form->logo_url,
                    'image_url' => $form->image_url,
                    'thank_you' => ['title' => $form->thank_you_title, 'message' => $form->thank_you_message],
                    'project' => $link->project_id ? ['name' => Project::whereKey($link->project_id)->value('name')] : null,
                    'fields' => $this->schema->publicShape($fields),
                    'steps' => $form->steps(),
                    'consent' => ['required' => (bool) $form->consent_required, 'text' => $form->consent_text, 'privacy_url' => $form->privacy_url],
                    // The tags this workspace asked the page to load: a public identifier each, never a credential.
                    'tags' => $this->tags($link->project_id),
                ],
                'tracking' => [
                    'visitor_id' => $visitor,
                    'session_id' => $session->session_id,
                    // The id the page's own pixel may use for the same view, so a platform counts it once.
                    'view_event_id' => $view?->event_id,
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

        $result = $this->submission->handle($page['link'], $page['form'], $page['tenant'], $in, [
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
            ->map(fn (TrackingDestination $d) => ['kind' => $d->kind, 'id' => (string) ((array) $d->config)[$d->destinationKind()->config()[0]]])
            ->values()->all();
    }
}
