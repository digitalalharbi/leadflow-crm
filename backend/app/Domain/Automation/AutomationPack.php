<?php

namespace App\Domain\Automation;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\ProjectQuestion;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;

/**
 * Everything a project needs for its leads to run on their own — eleven
 * answers, not dozens of rules.
 *
 * The journey is the workspace's and already applies to every project; what
 * makes it *this* project's is its service and language, its brochure, its
 * welcome on WhatsApp and by email, the questions it asks, and the team that
 * takes over. This says which of those are there, and fills the ones that can
 * be filled from sensible defaults so a new project is never silent.
 */
class AutomationPack
{
    public const STEPS = [
        'service', 'language', 'brochure', 'whatsapp_template', 'email_template', 'qualification', 'sales_team',
        'sla', 'scoring', 'cadence', 'quiet_hours',
    ];

    /** Asked when a project has no questions of its own yet. */
    private const DEFAULT_QUESTIONS = [
        ['key' => 'budget', 'label' => 'ما ميزانيتك التقريبية؟'],
        ['key' => 'timeline', 'label' => 'متى تنوي الشراء؟'],
        ['key' => 'purpose', 'label' => 'هل الشراء للسكن أم للاستثمار؟'],
    ];

    public function status(Project $project): array
    {
        $own = fn (string $channel) => MessageTemplate::where('channel', $channel)->where('purpose', 'welcome')->where('is_active', true)->where('project_id', $project->id)->exists();
        $general = fn (string $channel) => MessageTemplate::where('channel', $channel)->where('purpose', 'welcome')->where('is_active', true)->whereNull('project_id')->exists();
        $team = $project->team()->count();
        $mine = ProjectSettings::of($project);
        $workspaceQuiet = (bool) TenantSetting::query()->value('quiet_hours_enabled');

        $steps = [
            'service' => ['done' => filled($project->service), 'detail' => $project->service],
            'language' => ['done' => filled($project->language), 'detail' => $project->language],
            'brochure' => ['done' => ProjectFile::where('project_id', $project->id)->where('is_active', true)->where('kind', 'brochure')->exists(), 'detail' => null],
            'whatsapp_template' => ['done' => $own('whatsapp') || $general('whatsapp'), 'detail' => $own('whatsapp') ? 'project' : ($general('whatsapp') ? 'general' : null)],
            'email_template' => ['done' => $own('email') || $general('email'), 'detail' => $own('email') ? 'project' : ($general('email') ? 'general' : null)],
            'qualification' => ['done' => ($n = ProjectQuestion::where('project_id', $project->id)->where('is_active', true)->count()) > 0, 'detail' => (string) $n],
            // How fast the first call must happen, how leads are scored, how quickly the
            // journey follows up, and when it keeps quiet.
            'sla' => ['done' => (int) ($mine['sla_minutes'] ?? 0) > 0, 'detail' => isset($mine['sla_minutes']) ? (string) $mine['sla_minutes'] : null],
            'scoring' => ['done' => true, 'detail' => $project->scoring ? 'custom' : 'default'],
            'cadence' => ['done' => isset($mine['cadence']), 'detail' => $mine['cadence'] ?? null],
            'quiet_hours' => ['done' => isset($mine['quiet_hours']['enabled']) ? (bool) $mine['quiet_hours']['enabled'] : $workspaceQuiet, 'detail' => isset($mine['quiet_hours']['enabled']) ? 'project' : ($workspaceQuiet ? 'workspace' : null)],
            'sales_team' => ['done' => $team > 0 || User::query()->inWorkspace($project->tenant_id)->where('role', 'sales')->where('is_active', true)->exists(), 'detail' => $team > 0 ? 'project' : 'everyone'],
        ];

        return [
            'steps' => collect($steps)->map(fn ($step, $key) => ['key' => $key] + $step)->values()->all(),
            'done' => collect($steps)->where('done', true)->count(),
            'total' => count($steps),
            'journey_on' => AutomationRule::where('template_key', 'lead_journey')->where('is_enabled', true)->exists(),
            // Whether automation may contact this project's leads at all, and what the project has chosen.
            'active' => (bool) ($mine['active'] ?? true),
            'settings' => [
                'cadence' => $mine['cadence'] ?? 'standard',
                'sla_minutes' => $mine['sla_minutes'] ?? null,
                'quiet_hours' => $mine['quiet_hours'] ?? null,
            ],
        ];
    }

    /**
     * Fills what can be filled: the service and language given, a welcome on
     * each channel written for this project, and starter questions. Never
     * overwrites anything the project already has.
     */
    public function complete(Project $project, array $answers): array
    {
        $project->fill(array_filter([
            'service' => $answers['service'] ?? null,
            'language' => $answers['language'] ?? null,
        ], fn ($v) => filled($v)))->save();

        // The project's own pace, target and quiet window — only what was given.
        $settings = ProjectSettings::of($project);
        foreach (['cadence', 'sla_minutes', 'quiet_hours'] as $key) {
            if (array_key_exists($key, $answers) && $answers[$key] !== null) {
                $settings[$key] = $answers[$key];
            }
        }
        // A project that is set up runs at the standard pace unless it chose another.
        $settings['cadence'] ??= 'standard';
        $project->forceFill(['automation_settings' => $settings])->save();

        $language = $project->language ?: 'ar';
        $service = $project->service ?: $project->name;

        if (! MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'welcome')->where('project_id', $project->id)->exists()) {
            MessageTemplate::create([
                'name' => mb_substr("ترحيب {$project->name}", 0, 120),
                'body' => $language === 'en'
                    ? "Hello {{name}}, thank you for your interest in {$service} at {{project}}. We will send you the details now."
                    : "مرحبًا {{name}}، شكرًا لاهتمامك بـ{$service} في {{project}}. نرسل لك التفاصيل الآن.",
                'channel' => 'whatsapp', 'purpose' => 'welcome', 'project_id' => $project->id, 'language' => $language, 'is_active' => true,
            ]);
        }

        if (! MessageTemplate::where('channel', 'email')->where('purpose', 'welcome')->where('project_id', $project->id)->exists()) {
            MessageTemplate::create([
                'name' => mb_substr("بريد ترحيب {$project->name}", 0, 120),
                'subject' => $language === 'en' ? 'Your interest in {{project}}' : 'اهتمامك بـ{{project}}',
                'body' => $language === 'en'
                    ? "Hello {{name}},\n\nThank you for your interest in {$service} at {{project}}. The brochure is on its way, and we are happy to answer any question.\n\n{{agent}}"
                    : "مرحبًا {{name}}،\n\nشكرًا لاهتمامك بـ{$service} في {{project}}. البروشور في طريقه إليك، ويسعدنا الرد على أي سؤال.\n\n{{agent}}",
                'channel' => 'email', 'purpose' => 'welcome', 'project_id' => $project->id, 'language' => $language, 'is_active' => true,
            ]);
        }

        if (! ProjectQuestion::where('project_id', $project->id)->exists()) {
            foreach (self::DEFAULT_QUESTIONS as $order => $question) {
                ProjectQuestion::create($question + ['project_id' => $project->id, 'type' => 'text', 'is_active' => true, 'is_required' => false, 'sort_order' => $order]);
            }
        }

        return $this->status($project->refresh());
    }

    /**
     * "Activate Automation": complete what can be completed, make sure the
     * workspace's journey exists and is on, and let this project's leads be
     * contacted. One press, and the answer is the pack's new state.
     */
    public function activate(Project $project, array $answers = []): array
    {
        $this->complete($project, $answers);

        $tenant = $project->tenant ?? Tenant::find($project->tenant_id);
        if ($tenant) {
            app(JourneyInstaller::class)->install($tenant);
        }
        AutomationRule::whereIn('template_key', JourneyInstaller::RULES)->where('is_enabled', false)->update(['is_enabled' => true]);

        $settings = ProjectSettings::of($project->refresh());
        $settings['active'] = true;
        $project->forceFill(['automation_settings' => $settings])->save();

        return $this->status($project->refresh());
    }

    /** Pause this project's automation: its leads stop being contacted, nothing else changes. */
    public function pause(Project $project): array
    {
        $settings = ProjectSettings::of($project);
        $settings['active'] = false;
        $project->forceFill(['automation_settings' => $settings])->save();

        return $this->status($project->refresh());
    }
}
