<?php

namespace Tests\Feature;

use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A lead that has actually been worked for six months.
 *
 * The screens that degrade first are the ones that look at everything a lead
 * has ever done. The rule here is that a long history must not make the page
 * slower to open than a short one — the work is paginated, not loaded.
 */
class LeadDetailPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $busy;

    private Lead $quiet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Busy Realty');

        [$this->busy, $this->quiet] = $this->asTenant($this->t['tenant'], function () {
            $busy = Lead::create([
                'full_name' => 'عميل كثير النشاط',
                'phone' => '0551112222',
                'phone_normalized' => '+966551112222',
            ]);

            $quiet = Lead::create([
                'full_name' => 'عميل جديد',
                'phone' => '0551113333',
                'phone_normalized' => '+966551113333',
            ]);

            return [$busy, $quiet];
        });

        $this->fillHistory($this->busy, messages: 100, activities: 500);
    }

    public function test_the_lead_screen_does_not_slow_down_with_a_long_history(): void
    {
        $quietQueries = $this->countQueries(fn () => $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->quiet->uuid}")
            ->assertSuccessful());

        $busyQueries = $this->countQueries(fn () => $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}")
            ->assertSuccessful());

        // Flat: the history is capped and paginated, not walked.
        $this->assertSame(
            $quietQueries,
            $busyQueries,
            'A lead with a long history must cost the same number of queries as a new one.',
        );
    }

    public function test_the_lead_payload_stays_a_sensible_size(): void
    {
        $response = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}")
            ->assertSuccessful();

        $bytes = strlen($response->getContent());

        // 600 rows of history would be well over a megabyte unrendered.
        $this->assertLessThan(
            250_000,
            $bytes,
            sprintf('The lead payload grew to %s KB.', round($bytes / 1024)),
        );
    }

    public function test_the_conversation_is_paginated_rather_than_loaded(): void
    {
        $response = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}/messages")
            ->assertSuccessful();

        $this->assertCount(30, $response->json('data'));
        $this->assertSame(100, $response->json('meta.total'));
    }

    public function test_the_conversation_costs_the_same_on_any_page(): void
    {
        $first = $this->countQueries(fn () => $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}/messages?page=1")
            ->assertSuccessful());

        $last = $this->countQueries(fn () => $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}/messages?page=4")
            ->assertSuccessful());

        $this->assertSame($first, $last);
    }

    public function test_a_caller_cannot_ask_for_the_whole_history_at_once(): void
    {
        // Otherwise per_page is a denial-of-service parameter on a tenant with
        // a year of conversations.
        $response = $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$this->busy->uuid}/messages?per_page=10000")
            ->assertSuccessful();

        $this->assertLessThanOrEqual(100, count($response->json('data')));
    }

    private function fillHistory(Lead $lead, int $messages, int $activities): void
    {
        $now = now();

        $rows = [];
        for ($i = 0; $i < $messages; $i++) {
            $rows[] = [
                'tenant_id' => $this->t['tenant']->id,
                'lead_id' => $lead->id,
                'uuid' => (string) Str::uuid(),
                'direction' => ($i % 2 === 0 ? MessageDirection::Outbound : MessageDirection::Inbound)->value,
                'channel' => MessageChannel::Whatsapp->value,
                'provider' => 'mock',
                'provider_message_id' => 'wamid.perf.'.$i,
                'message_type' => 'text',
                'body' => 'رسالة رقم '.$i.' في محادثة طويلة مع العميل حول تفاصيل المشروع.',
                'status' => MessageStatus::Delivered->value,
                'sent_at' => $now->copy()->subMinutes($messages - $i),
                'created_at' => $now->copy()->subMinutes($messages - $i),
                'updated_at' => $now,
            ];
        }
        DB::table('lead_messages')->insert($rows);

        $rows = [];
        for ($i = 0; $i < $activities; $i++) {
            $rows[] = [
                'tenant_id' => $this->t['tenant']->id,
                'lead_id' => $lead->id,
                'uuid' => (string) Str::uuid(),
                'type' => 'updated',
                'description' => 'نشاط رقم '.$i,
                'created_at' => $now->copy()->subMinutes($activities - $i),
                'updated_at' => $now,
            ];
        }
        DB::table('lead_activities')->insert($rows);

        $this->assertSame($messages, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
        $this->assertSame($activities, LeadActivity::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $run();

        return $count;
    }
}
