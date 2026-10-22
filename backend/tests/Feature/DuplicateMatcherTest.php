<?php

namespace Tests\Feature;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Models\Lead;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deduplication decides whether two arrivals are one person.
 *
 * Both mistakes are expensive and only one is visible: a missed duplicate shows
 * up as two rows someone notices, while a wrong merge silently buries a real
 * customer inside somebody else's timeline and the second rep calls the first
 * rep's lead. These tests weight accordingly.
 */
class DuplicateMatcherTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private LeadIngestionService $ingestion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Dedup Co');
        $this->ingestion = app(LeadIngestionService::class);
    }

    private function arrive(array $payload, IntegrationProvider $provider = IntegrationProvider::GenericWebhook)
    {
        return $this->ingestion->ingest(
            $this->t['tenant'],
            IncomingLead::fromArray($provider, $payload),
        );
    }

    private function leadCount(): int
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::withTrashed()->count());
    }

    public function test_the_same_phone_in_a_different_format_is_one_person(): void
    {
        $this->arrive(['full_name' => 'سعود', 'phone' => '0551234567']);
        $this->arrive(['full_name' => 'سعود', 'phone' => '+966551234567']);

        $this->assertSame(1, $this->leadCount());
    }

    /**
     * The case phone matching alone could not catch: the same submission
     * delivered twice by a provider retrying a webhook it believed failed.
     */
    public function test_the_same_submission_redelivered_does_not_duplicate(): void
    {
        $payload = [
            'full_name' => 'نورة',
            'phone' => '0559876543',
            'provider_lead_id' => 'meta-abc-123',
        ];

        $this->arrive($payload, IntegrationProvider::Meta);
        $this->arrive($payload, IntegrationProvider::Meta);

        $this->assertSame(1, $this->leadCount());
    }

    /**
     * The same person, a week later, from a different platform, having typed a
     * different number. The provider id is what carries the identity across.
     */
    public function test_a_provider_id_match_wins_even_when_the_phone_differs(): void
    {
        $this->arrive([
            'full_name' => 'خالد',
            'phone' => '0551111111',
            'provider_lead_id' => 'tiktok-xyz-9',
        ], IntegrationProvider::TikTok);

        $this->arrive([
            'full_name' => 'خالد',
            'phone' => '0552222222',
            'provider_lead_id' => 'tiktok-xyz-9',
        ], IntegrationProvider::TikTok);

        $this->assertSame(1, $this->leadCount());
    }

    /** Two platforms reuse an id space; they are not the same person. */
    public function test_the_same_provider_id_from_a_different_provider_is_a_different_person(): void
    {
        $this->arrive(['full_name' => 'أ', 'phone' => '0553333333', 'provider_lead_id' => '1'],
            IntegrationProvider::Meta);
        $this->arrive(['full_name' => 'ب', 'phone' => '0554444444', 'provider_lead_id' => '1'],
            IntegrationProvider::TikTok);

        $this->assertSame(2, $this->leadCount());
    }

    public function test_an_email_only_arrival_matches_an_earlier_email_only_arrival(): void
    {
        $this->arrive(['full_name' => 'بدر', 'email' => 'Badr@Example.com']);
        $this->arrive(['full_name' => 'بدر', 'email' => 'badr@example.com']);

        $this->assertSame(1, $this->leadCount());
    }

    /**
     * The merge that must not happen. A household or an office shares an
     * address; two people enquiring from it are two customers, and folding
     * them together loses one of them entirely.
     */
    public function test_a_shared_email_does_not_merge_two_people_who_gave_phones(): void
    {
        $this->arrive(['full_name' => 'الأب', 'phone' => '0555555555', 'email' => 'family@example.com']);
        $this->arrive(['full_name' => 'الابن', 'phone' => '0556666666', 'email' => 'family@example.com']);

        $this->assertSame(2, $this->leadCount(), 'Two different people were merged on a shared email.');
    }

    public function test_different_people_stay_separate(): void
    {
        $this->arrive(['full_name' => 'أول', 'phone' => '0557777777']);
        $this->arrive(['full_name' => 'ثاني', 'phone' => '0558888888']);

        $this->assertSame(2, $this->leadCount());
    }

    /** Every arrival is kept for attribution, even the ones that merged. */
    public function test_a_merged_arrival_still_records_its_own_attribution(): void
    {
        $this->arrive(['full_name' => 'عبدالله', 'phone' => '0551010101', 'campaign_name' => 'حملة مُيتا']);
        $this->arrive(['full_name' => 'عبدالله', 'phone' => '0551010101', 'campaign_name' => 'حملة تيك توك'],
            IntegrationProvider::TikTok);

        $this->asTenant($this->t['tenant'], function () {
            $lead = Lead::where('phone_normalized', '+966551010101')->first();

            $this->assertSame(1, Lead::count());
            $this->assertSame(2, $lead->sourceEvents()->count(),
                'A second touchpoint was lost, so attribution cannot see both campaigns.');
        });
    }
}
