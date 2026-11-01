<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledAction;
use App\Models\Lead;
use App\Models\ScheduledAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A wait that is promised is a wait that ends: a job that dies is recorded as failed and the managers
 * are told; a delayed job that was lost is found again; and two workers never run one step twice.
 */
class ScheduledActionsSurviveTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Survive Co');
    }

    private function waiting(int $minutesAgo = 0, array $action = ['type' => 'add_note', 'body' => 'ملاحظة آلية']): ScheduledAction
    {
        return $this->asTenant($this->t['tenant'], function () use ($minutesAgo, $action) {
            $lead = Lead::create(['full_name' => 'عميل', 'phone' => '0551234567', 'phone_normalized' => '+9665512'.self::uniqueDigits(5), 'status' => 'new']);

            return ScheduledAction::create(['lead_id' => $lead->id, 'rule_name' => 'قاعدة', 'action' => $action, 'run_at' => now()->subMinutes($minutesAgo), 'status' => ScheduledAction::PENDING]);
        });
    }

    public function test_a_step_that_keeps_failing_is_marked_failed_and_the_managers_are_told_once(): void
    {
        $one = $this->waiting(30);
        $two = $this->waiting(30);

        (new RunScheduledAction($one->id))->failed(new \RuntimeException('database went away'));
        (new RunScheduledAction($two->id))->failed(new \RuntimeException('database went away'));

        $row = ScheduledAction::withoutGlobalScopes()->find($one->id);
        $this->assertSame(ScheduledAction::FAILED, $row->status);
        $this->assertStringNotContainsString('database went away', (string) $row->outcome, 'the reason shown is words, never the exception');

        $told = DatabaseNotification::where('notifiable_id', $this->t['admin']->id)->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'automation_failed');
        $this->assertCount(1, $told, 'two failures in a day are one message');
    }

    public function test_a_step_that_already_ran_is_left_alone_when_the_failure_arrives_late(): void
    {
        $row = $this->waiting(30);
        $row->forceFill(['status' => ScheduledAction::DONE])->saveQuietly();

        (new RunScheduledAction($row->id))->failed(new \RuntimeException('late'));

        $this->assertSame(ScheduledAction::DONE, ScheduledAction::withoutGlobalScopes()->find($row->id)->status);
    }

    public function test_the_catch_up_finds_what_is_overdue_and_nothing_else(): void
    {
        Queue::fake([RunScheduledAction::class]);
        $overdue = $this->waiting(30);
        $this->waiting(2);
        $done = $this->waiting(90);
        $done->forceFill(['status' => ScheduledAction::DONE])->saveQuietly();
        $future = $this->waiting(0);
        $future->forceFill(['run_at' => now()->addHours(3)])->saveQuietly();

        $this->artisan('leadflow:sweep-scheduled')->assertSuccessful();

        Queue::assertPushed(RunScheduledAction::class, 1);
        Queue::assertPushed(RunScheduledAction::class, fn (RunScheduledAction $job) => $job->scheduledActionId === $overdue->id);
    }

    public function test_two_workers_cannot_run_the_same_step_twice(): void
    {
        $row = $this->waiting(5);

        $lock = Cache::lock("scheduled-action:{$row->id}", 120);
        $this->assertTrue($lock->get(), 'the first worker has it');

        app()->call([new RunScheduledAction($row->id), 'handle']);

        $this->assertSame(ScheduledAction::PENDING, ScheduledAction::withoutGlobalScopes()->find($row->id)->status, 'the second worker did nothing');
        $lock->release();

        app()->call([new RunScheduledAction($row->id), 'handle']);
        $this->assertSame(ScheduledAction::DONE, ScheduledAction::withoutGlobalScopes()->find($row->id)->status, 'and it runs once the first has let go');
    }
}
