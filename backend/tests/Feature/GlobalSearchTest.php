<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One box, everything findable — and nothing findable that should not be.
 *
 * A search box is the easiest place in a product to leak data: it reaches
 * across every table, it is used constantly, and a missing scope shows up as a
 * helpful result rather than an error.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Search Co');
    }

    private function search(string $term, $as = null): array
    {
        return $this->actingAs($as ?? $this->t['admin'])
            ->getJson('/api/v1/search?q='.urlencode($term))
            ->assertOk()
            ->json('data');
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'نورة القحطاني',
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
            'status' => 'new',
        ], $attributes)));
    }

    public function test_it_finds_a_lead_by_name(): void
    {
        $this->lead();

        $this->assertSame('نورة القحطاني', $this->search('نورة')['leads'][0]['name']);
    }

    /**
     * The case this exists for: a rep with a ringing phone has the number and
     * nothing else, and it will not be in the format we stored.
     */
    public function test_it_finds_a_lead_by_phone_in_any_format(): void
    {
        $this->lead();

        foreach (['0512345678', '512345678', '+966512345678', '966512345678'] as $format) {
            $this->assertNotEmpty($this->search($format)['leads'], "{$format} found nothing");
        }
    }

    public function test_it_finds_projects(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'مشروع النرجس']));

        $this->assertSame('مشروع النرجس', $this->search('النرجس')['projects'][0]['name']);
    }

    /** A rep finds their own leads here and nobody else's, same as the list. */
    public function test_a_rep_cannot_find_another_reps_lead(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $other = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->lead(['full_name' => 'عميل زميلي', 'assigned_to' => $other->id]);

        $this->assertEmpty($this->search('زميلي', $rep)['leads']);
    }

    /**
     * A rep has no business enumerating the team through a search box, and an
     * email address is the sort of thing that leaks one query at a time.
     */
    public function test_a_rep_cannot_search_colleagues(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $colleague = $this->makeUser($this->t['tenant'], Role::Sales, ['name' => 'نورة الشمري']);

        $this->assertEmpty(
            $this->search('نورة', $rep)['people'],
            'a rep enumerating the team through a search box is how an email list leaks one query at a time',
        );

        $found = $this->search('نورة')['people'];

        $this->assertNotEmpty($found, 'an admin should still find them');
        $this->assertSame($colleague->uuid, $found[0]['id']);
    }

    /** The whole point of a shared search box, and the easiest thing to get wrong. */
    public function test_it_never_reaches_another_workspace(): void
    {
        $other = $this->provisionTenant('Elsewhere Co');

        $this->asTenant($other['tenant'], function () {
            Lead::create(['full_name' => 'سر مكتوم', 'phone_normalized' => '+966599999999', 'status' => 'new']);
            Project::create(['name' => 'مشروع سري']);
        });

        $results = $this->search('سر');

        $this->assertEmpty($results['leads']);
        $this->assertEmpty($results['projects']);
    }

    /** A test lead is not a customer and should not surface as one. */
    public function test_it_does_not_surface_the_setup_test_lead(): void
    {
        $this->actingAs($this->t['admin'])->postJson('/api/v1/onboarding/test-lead')->assertOk();

        $this->assertEmpty($this->search('تجريبي')['leads']);
    }

    /** Two characters matches half the database and tells nobody anything. */
    public function test_a_one_character_term_returns_nothing(): void
    {
        $this->lead();

        $results = $this->search('ن');

        $this->assertEmpty($results['leads']);
        $this->assertEmpty($results['projects']);
    }

    public function test_results_are_capped_so_the_palette_stays_readable(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            foreach (range(1, 12) as $i) {
                Lead::create([
                    'full_name' => "عميل بحث {$i}",
                    'phone_normalized' => '+96650000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'status' => 'new',
                ]);
            }
        });

        $this->assertCount(5, $this->search('بحث')['leads']);
    }
}
