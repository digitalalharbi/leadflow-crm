<?php

namespace App\Domain\Onboarding;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\AutomationRule;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;

/**
 * How far a workspace is from actually running on this.
 *
 * Deliberately not a wizard that walks somebody through forms that already
 * exist elsewhere. A wizard that reimplements the settings screens creates two
 * places to change the same thing, and the second one rots — the version
 * people meet on their first day is the one nobody maintains.
 *
 * So this assesses the real state and points at the real screen. Every item is
 * answered by looking at the data, never by a "completed" flag somebody could
 * tick without doing the thing: a checklist that can be satisfied by pressing
 * Next is a checklist that lies on the day it matters.
 */
class SetupChecklist
{
    public function for(Tenant $tenant): array
    {
        $steps = [
            $this->identity($tenant),
            $this->projects(),
            $this->team(),
            $this->sources(),
            $this->assignment($tenant),
            $this->sla($tenant),
            $this->automation(),
            $this->testLead(),
        ];

        $done = count(array_filter($steps, fn (array $s) => $s['done']));

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            /*
             * "complete", not "ready". Whether a sales floor is ready to run on
             * this is their call; all this can honestly say is that nothing it
             * knows how to check is missing.
             */
            'complete' => $done === count($steps),
        ];
    }

    private function identity(Tenant $tenant): array
    {
        return $this->step('identity', filled($tenant->branding?->company_name), '/settings');
    }

    private function projects(): array
    {
        return $this->step('projects', Project::query()->exists(), '/projects');
    }

    /**
     * Somebody to give leads to.
     *
     * Counts sales and managers, not admins: a workspace whose only user is the
     * owner can be configured perfectly and still have nobody to call anyone,
     * and round-robin over an empty list assigns nothing at all.
     */
    private function team(): array
    {
        $hasSellers = User::query()
            ->whereIn('role', [Role::Sales->value, Role::Manager->value])
            ->where('is_active', true)
            ->exists();

        return $this->step('team', $hasSellers, '/users');
    }

    /**
     * Are leads actually arriving from anywhere?
     *
     * Not "is an integration connected" — every workspace is provisioned with
     * a working generic webhook from minute one, so that question answers
     * itself for everybody and tells nobody anything. A checklist item that is
     * ticked before the customer has done anything is decoration.
     *
     * Satisfied either by somebody deliberately connecting a platform, or by
     * leads having actually come through an endpoint. A webhook URL nobody has
     * ever posted to is not a source.
     */
    private function sources(): array
    {
        $live = Integration::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('leads_received', '>', 0)
                ->orWhere(fn ($inner) => $inner
                    ->where('status', Integration::CONNECTED)
                    ->where('provider', '!=', IntegrationProvider::GenericWebhook->value)))
            ->exists();

        return $this->step('sources', $live, '/integrations');
    }

    /**
     * Manual assignment is a legitimate choice, so this passes either way —
     * but only once somebody has actually chosen. A workspace sitting on the
     * untouched default has decided nothing, and the symptom is a pile of
     * unassigned leads that nobody notices for a week.
     */
    private function assignment(Tenant $tenant): array
    {
        $settings = $tenant->settings;

        return $this->step(
            'assignment',
            $settings?->assignment_mode === 'round_robin' || filled($settings?->round_robin_cursor),
            '/settings',
        );
    }

    private function sla(Tenant $tenant): array
    {
        return $this->step(
            'sla',
            ($tenant->settings?->first_contact_target_minutes ?? 0) > 0,
            '/settings',
        );
    }

    private function automation(): array
    {
        return $this->step(
            'automation',
            AutomationRule::query()->where('is_enabled', true)->exists(),
            '/automation',
        );
    }

    /** The only item that proves the others actually work together. */
    private function testLead(): array
    {
        return $this->step(
            'test_lead',
            Lead::withTestLeads()->where('is_test', true)->exists(),
            '/settings',
        );
    }

    private function step(string $key, bool $done, string $where): array
    {
        return ['key' => $key, 'done' => $done, 'where' => $where];
    }
}
