<?php

namespace App\Notifications;

use App\Models\Project;
use App\Notifications\Channels\TenantDatabaseChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The four things that happen in a repository and are worth interrupting
 * somebody for.
 *
 * Deliberately four, not everything. A notification per push is a notification
 * people turn off, and the one that mattered goes with it. These are the ones
 * where somebody has to decide something:
 *
 *   - production broke
 *   - a deploy failed
 *   - production shipped
 *   - a version was released
 *
 * A pull request opening is not one of them: it is on a screen somebody is
 * already looking at, and whoever needs to review it was told by GitHub.
 */
class RepositoryEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CI_FAILED = 'ci_failed';

    public const DEPLOY_FAILED = 'deploy_failed';

    public const DEPLOYED = 'deployed';

    public const RELEASED = 'released';

    public function __construct(
        public Project $project,
        public string $kind,
        /** The branch, environment or tag — whatever names the thing. */
        public ?string $subject = null,
        public ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function toDatabase(object $notifiable): array
    {
        [$title, $body] = match ($this->kind) {
            self::CI_FAILED => ['فشل البناء', "فشل البناء في {$this->project->name}".($this->subject ? " على {$this->subject}" : '')],
            self::DEPLOY_FAILED => ['فشل النشر', "فشل نشر {$this->project->name}".($this->subject ? " إلى {$this->subject}" : '')],
            self::DEPLOYED => ['تم النشر', "نُشر {$this->project->name}".($this->subject ? " إلى {$this->subject}" : '').' — لم يُتحقَّق منه بعد'],
            self::RELEASED => ['إصدار جديد', "صدر {$this->subject} من {$this->project->name}"],
            default => ['تحديث من المستودع', $this->project->name],
        };

        return [
            'type' => 'repository.'.$this->kind,
            'title' => $title,
            'body' => $body,
            'project_id' => $this->project->uuid,
            'url' => $this->url,
        ];
    }
}
