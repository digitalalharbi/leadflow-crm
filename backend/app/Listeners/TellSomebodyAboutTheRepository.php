<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Models\Project;
use App\Models\RepositoryActivity;
use App\Models\User;
use App\Notifications\RepositoryEventNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Notification;

/**
 * Turning a line of the development timeline into somebody being told.
 *
 * Only the four events where a person has to decide something, and only to the
 * people who can act on them: a build that broke is a manager's problem and a
 * rep's noise. Everything else stays on the screen, where it is already visible
 * to whoever opened it.
 *
 * Deliberately not an automation rule. The automation engine is lead-shaped
 * all the way down — its runs point at a lead, its conditions read lead fields,
 * its actions act on one — and making it project-aware is a change to the lead
 * path to deliver a notification. That trade is not worth taking for four
 * fixed events; a project-rule engine is its own piece of work, and this is
 * honest about being a smaller thing.
 */
class TellSomebodyAboutTheRepository
{
    /** @var array<string,string> */
    private const WORTH_INTERRUPTING = [
        'ci_failed' => RepositoryEventNotification::CI_FAILED,
        'deploy_failed' => RepositoryEventNotification::DEPLOY_FAILED,
        'deployed' => RepositoryEventNotification::DEPLOYED,
        'released' => RepositoryEventNotification::RELEASED,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(RepositoryActivity $activity, Project $project): void
    {
        /*
         * Every one of these is recorded in the audit trail, whether or not
         * anybody is told — and recorded as coming from GitHub rather than from
         * whoever happened to be signed in. A merge is not an action a مهتم
         * user took, and attributing it to one would make the trail a work of
         * fiction the first time somebody audited a deployment.
         */
        $this->audit->log('github.'.$activity->kind, null, $project, [
            'source' => 'github',
            'ref' => $activity->ref,
            'actor' => $activity->actor,
        ]);

        $kind = self::WORTH_INTERRUPTING[$activity->kind] ?? null;

        if ($kind === null) {
            return;
        }

        // Production only for the good news. A staging deploy is a Tuesday.
        if ($kind === RepositoryEventNotification::DEPLOYED
            && ($activity->meta['environment'] ?? $activity->ref) !== 'production'
            && ! str_contains((string) $activity->title, 'production')) {
            return;
        }

        $recipients = User::query()
            ->where('tenant_id', $project->tenant_id)
            ->whereIn('role', [Role::Admin->value, Role::Manager->value])
            ->available()
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new RepositoryEventNotification(
            $project,
            $kind,
            $activity->ref ?? $activity->title,
            $activity->url,
        ));
    }
}
