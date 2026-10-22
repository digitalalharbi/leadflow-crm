<?php

namespace App\Domain\Automation;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\Tenant;

/**
 * Puts the lead journey in a workspace: the rules, and the messages they need.
 *
 * Idempotent, because it runs for every new workspace and once, in a
 * migration, for every existing one — and a workspace that has edited or
 * switched off its journey has made a decision this must not undo. A rule that
 * is already there by its template key is left exactly as it is.
 */
class JourneyInstaller
{
    /** The rules every lead runs through, in the order a person would read them. */
    public const RULES = ['lead_journey', 'reply_hand_off', 'qualified_hand_off'];

    public function install(Tenant $tenant): void
    {
        $this->tagDefaultTemplates();

        $hasQualification = MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'qualification')->exists();

        if (! $hasQualification) {
            MessageTemplate::create([
                'name' => MessageTemplate::QUALIFICATION_DEFAULT['name'],
                'body' => MessageTemplate::QUALIFICATION_DEFAULT['body'],
                'purpose' => 'qualification',
                'channel' => 'whatsapp',
                'is_active' => true,
                'sort_order' => 10,
            ]);
        }

        // Deleted counts as decided: a workspace that removed its journey keeps
        // it removed, and a new insert would collide with the old row's key.
        if (! MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'nurture')->exists()
            && ! MessageTemplate::where('name', MessageTemplate::NURTURE_DEFAULT['name'])->exists()) {
            MessageTemplate::create([
                'name' => MessageTemplate::NURTURE_DEFAULT['name'],
                'body' => MessageTemplate::NURTURE_DEFAULT['body'],
                'purpose' => 'nurture',
                'channel' => 'whatsapp',
                'is_active' => true,
                'sort_order' => 11,
            ]);
        }

        if (! MessageTemplate::where('channel', 'whatsapp')->where('purpose', 'brochure')->exists()
            && ! MessageTemplate::where('name', MessageTemplate::BROCHURE_DEFAULT['name'])->exists()) {
            MessageTemplate::create([
                'name' => MessageTemplate::BROCHURE_DEFAULT['name'],
                'body' => MessageTemplate::BROCHURE_DEFAULT['body'],
                'purpose' => 'brochure',
                'channel' => 'whatsapp',
                'is_active' => true,
                'sort_order' => 12,
            ]);
        }

        $installed = AutomationRule::withTrashed()->pluck('template_key')->filter()->all();

        foreach (self::RULES as $key) {
            if (in_array($key, $installed, true)) {
                continue;
            }

            $template = RuleTemplates::get($key);

            AutomationRule::create([
                'name' => $template['name'],
                'trigger' => $template['trigger'],
                'priority' => $template['priority'],
                'conditions' => $template['conditions'],
                'actions' => array_map(fn (array $action) => $action + ['rule_name' => $template['name']], $template['actions']),
                'is_enabled' => true,
                'template_key' => $key,
            ]);
        }
    }

    /**
     * Workspaces created before templates had a purpose still have the
     * defaults, under their default names. Those are tagged; anything a
     * workspace renamed or wrote itself is left for it to tag.
     */
    private function tagDefaultTemplates(): void
    {
        foreach ([['whatsapp', MessageTemplate::DEFAULTS], ['email', MessageTemplate::EMAIL_DEFAULTS]] as [$channel, $defaults]) {
            foreach ($defaults as $default) {
                if (! isset($default['purpose'])) {
                    continue;
                }

                MessageTemplate::where('channel', $channel)
                    ->where('name', $default['name'])
                    ->whereNull('purpose')
                    ->update(['purpose' => $default['purpose']]);
            }
        }
    }
}
