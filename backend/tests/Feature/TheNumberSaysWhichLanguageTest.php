<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\Leads\LeadAssignmentService;
use App\Services\Leads\PhoneLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The language comes from the country code and the number.
 *
 * Nothing on a lead form says what language the person speaks. The name is
 * typed by whoever filled the form, and the form's own language says which ad
 * they clicked. The number is the one field that comes from somewhere real —
 * a +92 number was issued in Pakistan — so it is what routing reads.
 *
 * Most of what is tested here is the refusal to act on it. A number whose
 * country has no single language, a number nobody normalised, a language nobody
 * on the team has claimed: all of them have to end with the lead owned by
 * somebody, because a lead matched to nobody is worse than one matched
 * imperfectly.
 */
class TheNumberSaysWhichLanguageTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Languages Co');
        $this->t['tenant']->settings->forceFill([
            'assignment_mode' => 'round_robin',
            'assignment_strategy' => 'workload',
        ])->save();
    }

    private function reads(?string $number): ?string
    {
        return app(PhoneLanguage::class)->for($number);
    }

    private function rep(array $attributes = []): User
    {
        $rep = $this->makeUser($this->t['tenant']);

        if ($attributes !== []) {
            $rep->forceFill($attributes)->save();
        }

        return $rep->fresh();
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'عميل',
            'phone_normalized' => '+96650030'.self::uniqueDigits(4),
            'status' => 'new',
        ], $attributes)));
    }

    private function pickFor(Lead $lead): ?User
    {
        return $this->asTenant(
            $this->t['tenant'],
            fn () => app(LeadAssignmentService::class)->pickAssignee($this->t['tenant'], $lead),
        );
    }

    // ---------------------------------------------------------------- reading

    public function test_it_reads_the_language_off_the_calling_code(): void
    {
        $this->assertSame('ar', $this->reads('+966512345678'));
        $this->assertSame('ar', $this->reads('+201001234567'));
        $this->assertSame('en', $this->reads('+447700900123'));
        $this->assertSame('en', $this->reads('+12025550123'));
        $this->assertSame('ur', $this->reads('+923001234567'));
        $this->assertSame('tr', $this->reads('+905321234567'));
        $this->assertSame('ru', $this->reads('+79161234567'));
    }

    /**
     * The longest code wins, or every Gulf number would be read as whatever
     * +9 or +97 happened to be in the table.
     */
    public function test_a_longer_calling_code_is_not_read_as_a_shorter_one(): void
    {
        $this->assertSame('ar', $this->reads('+971501234567'));   // not +97
        $this->assertSame('bn', $this->reads('+8801712345678'));  // not +88
        $this->assertSame('uk', $this->reads('+380501234567'));   // not +38
    }

    /**
     * A country whose code does not pick one language gets no answer, rather
     * than whichever of its languages happened to be listed first. Switzerland
     * speaks German, French and Italian and the number cannot say which.
     */
    public function test_a_country_with_no_single_language_says_nothing(): void
    {
        $this->assertNull($this->reads('+41791234567'));  // Switzerland
        $this->assertNull($this->reads('+32470123456')); // Belgium
    }

    /**
     * Without the country code the number is unreadable: 0551234567 is a
     * Riyadh mobile, a Turkish one and several others at once. Falling back on
     * the workspace's default country would make every un-normalised number
     * agree with the default and tell nobody anything.
     */
    public function test_a_number_nobody_normalised_says_nothing(): void
    {
        $this->assertNull($this->reads('0551234567'));
        $this->assertNull($this->reads('551234567'));
        $this->assertNull($this->reads(null));
        $this->assertNull($this->reads(''));
    }

    public function test_an_unlisted_country_says_nothing_rather_than_guessing(): void
    {
        $this->assertNull($this->reads('+2991234567'));  // Greenland
    }

    /** Derived from the number on every read, so the two can never disagree. */
    public function test_a_corrected_number_changes_the_language_with_it(): void
    {
        $lead = $this->lead(['phone_normalized' => '+447700900123']);
        $this->assertSame('en', $lead->language());

        $lead->forceFill(['phone_normalized' => '+966512345678'])->save();

        $this->assertSame('ar', $lead->fresh()->language());
    }

    // ---------------------------------------------------------------- routing

    public function test_a_lead_goes_to_a_rep_who_speaks_their_language(): void
    {
        $arabic = $this->rep(['speaks' => ['ar']]);
        $english = $this->rep(['speaks' => ['en']]);

        $this->assertSame($english->id, $this->pickFor($this->lead(['phone_normalized' => '+447700900123']))?->id);
        $this->assertSame($arabic->id, $this->pickFor($this->lead(['phone_normalized' => '+966512345678']))?->id);
    }

    /** A rep usually speaks more than one, which is the ordinary case here. */
    public function test_a_rep_can_claim_several_languages(): void
    {
        $both = $this->rep(['speaks' => ['ar', 'en']]);
        $this->rep(['speaks' => ['ur']]);

        $this->assertSame($both->id, $this->pickFor($this->lead(['phone_normalized' => '+447700900123']))?->id);
        $this->assertSame($both->id, $this->pickFor($this->lead(['phone_normalized' => '+966512345678']))?->id);
    }

    /**
     * The region is dropped on purpose: somebody who speaks Egyptian Arabic can
     * talk to a customer in Jeddah, and a routing rule that disagreed would be
     * wrong about people.
     */
    public function test_a_claim_is_read_without_its_region_or_its_case(): void
    {
        $rep = $this->rep(['speaks' => ['AR-EG']]);
        $this->rep(['speaks' => ['ur']]);

        $this->assertSame($rep->id, $this->pickFor($this->lead(['phone_normalized' => '+966512345678']))?->id);
    }

    /**
     * The default, and the case that covers almost every workspace. Nobody has
     * said what they speak, so nothing narrows and whoever is least busy gets
     * the lead — exactly as before this existed.
     */
    public function test_it_narrows_nothing_until_somebody_claims_a_language(): void
    {
        $first = $this->rep();
        $second = $this->rep();

        // Two reps, neither claiming anything, and a lead from a country
        // neither was chosen for: the workload balancer decides, and it decides
        // on the open count rather than on language.
        $this->lead(['assigned_to' => $first->id, 'phone_normalized' => '+447700900124']);

        $this->assertSame($second->id, $this->pickFor($this->lead(['phone_normalized' => '+447700900123']))?->id);
    }

    /**
     * The refusal that matters most. A Nepali lead arrives at a team of Arabic
     * and English speakers — nobody can claim it, and the lead still has to end
     * up on somebody's queue. A rep reaching for a colleague or an interpreter
     * is better than a lead nobody ever calls.
     */
    public function test_a_language_nobody_speaks_still_gets_an_owner(): void
    {
        $arabic = $this->rep(['speaks' => ['ar']]);
        $this->rep(['speaks' => ['en']]);

        // Both are busy with one each, so workload breaks the tie predictably.
        $this->lead(['assigned_to' => $arabic->id]);

        $picked = $this->pickFor($this->lead(['phone_normalized' => '+9779812345678']));

        $this->assertNotNull($picked);
        $this->assertNotSame($arabic->id, $picked->id, 'the least busy of the two, not nobody');
    }

    /** A number that says nothing is nobody's exception either. */
    public function test_a_lead_with_no_readable_number_still_gets_an_owner(): void
    {
        $rep = $this->rep(['speaks' => ['en']]);

        $this->assertSame($rep->id, $this->pickFor($this->lead(['phone_normalized' => null]))?->id);
    }

    /**
     * Knowing the project beats speaking the language. What the customer rings
     * about is the compound, and the rep who knows what the service charge
     * covers can work through a language gap faster than the rep who has to go
     * and ask somebody.
     */
    public function test_the_projects_own_team_beats_the_language(): void
    {
        $onProject = $this->rep(['speaks' => ['ar']]);
        $englishSpeaker = $this->rep(['speaks' => ['en']]);

        $project = $this->asTenant($this->t['tenant'], fn () => Project::create([
            'name' => 'النرجس',
        ]));
        $onProject->projects()->attach($project->id);

        $picked = $this->pickFor($this->lead([
            'project_id' => $project->id,
            'phone_normalized' => '+447700900123',
        ]));

        $this->assertSame($onProject->id, $picked?->id);
        $this->assertNotSame($englishSpeaker->id, $picked?->id);
    }

    /**
     * Speaking the language beats covering the city: a rep who cannot be
     * understood is no nearer the customer for being in the same place.
     */
    public function test_speaking_the_language_beats_covering_the_city(): void
    {
        $this->rep(['covers_cities' => ['الرياض'], 'speaks' => ['ar']]);
        $elsewhere = $this->rep(['covers_cities' => ['جدة'], 'speaks' => ['en']]);

        $picked = $this->pickFor($this->lead([
            'city' => 'الرياض',
            'phone_normalized' => '+447700900123',
        ]));

        $this->assertSame($elsewhere->id, $picked?->id);
    }

    /** Both together, where they agree, is the case the team actually wants. */
    public function test_the_city_still_decides_among_people_who_share_the_language(): void
    {
        $riyadh = $this->rep(['covers_cities' => ['الرياض'], 'speaks' => ['ar', 'en']]);
        $this->rep(['covers_cities' => ['جدة'], 'speaks' => ['ar', 'en']]);
        $this->rep(['speaks' => ['ur']]);

        $picked = $this->pickFor($this->lead([
            'city' => 'الرياض',
            'phone_normalized' => '+447700900123',
        ]));

        $this->assertSame($riyadh->id, $picked?->id);
    }

    /** Being away outranks everything: an away speaker is not a speaker today. */
    public function test_the_only_speaker_being_away_does_not_hold_the_lead(): void
    {
        $this->rep(['speaks' => ['en'], 'away_until' => now()->addWeek()]);
        $arabic = $this->rep(['speaks' => ['ar']]);

        $this->assertSame($arabic->id, $this->pickFor($this->lead(['phone_normalized' => '+447700900123']))?->id);
    }

    // -------------------------------------------------------------------- api

    public function test_a_manager_can_say_what_a_rep_speaks(): void
    {
        $rep = $this->rep();

        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['speaks' => ['ar', 'en']])
            ->assertOk()
            ->assertJsonPath('data.user.speaks', ['ar', 'en']);
    }

    /**
     * The manager path above is the point of a separate authority, so this is
     * the half that stops it being a loophole: a manager takes leads like
     * everybody else, and the one thing a share must not be is something you
     * can award yourself.
     */
    public function test_a_manager_may_not_set_their_own_share(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->actingAs($manager)
            ->patchJson("/api/v1/users/{$manager->uuid}", ['speaks' => ['ar'], 'assignment_weight' => 5])
            ->assertForbidden();

        $this->assertSame(1, $manager->fresh()->assignmentWeight());
        $this->assertSame([], $manager->fresh()->speaks ?? []);
    }

    /**
     * Everything that is not about sharing out leads stays an admin's. A
     * manager with a narrower authority must not get a wider one by sending
     * the two in the same request.
     */
    public function test_a_manager_cannot_reach_the_rest_of_an_account_through_this(): void
    {
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);
        $rep = $this->rep();

        $this->actingAs($manager)
            ->patchJson("/api/v1/users/{$rep->uuid}", [
                'speaks' => ['en'],
                'email' => 'taken@example.test',
            ])
            ->assertForbidden();

        $this->assertSame([], $rep->fresh()->speaks ?? []);
    }

    /**
     * A rep who could narrow their own list could quietly opt out of the half
     * of the pipeline they do not want, which is the same reason coverage and
     * weight are not theirs to set.
     */
    public function test_a_rep_may_not_narrow_their_own_languages(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['speaks' => ['ar']])
            ->assertForbidden();

        $this->assertSame([], $rep->fresh()->speaks ?? []);
    }

    /**
     * And not by hiding it in a request that is otherwise allowed.
     *
     * Editing your own name is yours to do, so this request gets past the
     * account check and has to be stopped on the field itself — which is why
     * the field guard exists as well as the policy.
     */
    public function test_a_rep_cannot_slip_a_language_change_in_beside_their_own_name(): void
    {
        $rep = $this->rep();

        $this->actingAs($rep)
            ->patchJson("/api/v1/users/{$rep->uuid}", ['name' => 'اسمي الجديد', 'speaks' => ['ar']])
            ->assertJsonValidationErrors('assignment_weight');

        $this->assertSame([], $rep->fresh()->speaks ?? []);
        $this->assertNotSame('اسمي الجديد', $rep->fresh()->name, 'the whole request is refused, not half of it');
    }

    public function test_something_that_is_not_a_language_code_is_refused(): void
    {
        $rep = $this->rep();

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/users/{$rep->uuid}", ['speaks' => ['يتحدث العربية بطلاقة']])
            ->assertStatus(422);
    }

    /**
     * The team screen offers exactly the languages the system can read off a
     * number, served rather than hardcoded in the interface — a claim the
     * system can never match is a checkbox that does nothing.
     */
    public function test_the_team_screen_is_offered_the_languages_that_can_be_matched(): void
    {
        $languages = $this->actingAs($this->t['admin'])
            ->getJson('/api/v1/users')
            ->assertOk()
            ->json('data.languages');

        $this->assertSame(['ar', 'en'], array_slice($languages, 0, 2), 'the two that matter here come first');
        $this->assertSame($languages, array_values(array_unique($languages)));
        $this->assertContains('ur', $languages);
    }

    /** So a rep opening the lead knows which language to open the call in. */
    public function test_the_lead_carries_the_language_it_was_read_as(): void
    {
        $lead = $this->lead(['phone_normalized' => '+923001234567']);

        $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}")
            ->assertOk()
            ->assertJsonPath('data.lead.language', 'ur');
    }

    /**
     * And on the list, not only the detail screen.
     *
     * The moment it is useful is before dialling, in the work queue. Finding
     * out after the call that the customer does not speak Arabic is finding out
     * too late, and the queue reads the slim payload.
     */
    public function test_the_work_queue_payload_carries_it_too(): void
    {
        $rep = $this->rep();
        $this->lead(['phone_normalized' => '+905321234567', 'assigned_to' => $rep->id]);

        $this->actingAs($rep)
            ->getJson('/api/v1/work/next')
            ->assertOk()
            ->assertJsonPath('data.lead.language', 'tr');
    }

    public function test_a_lead_whose_number_says_nothing_carries_no_language(): void
    {
        $lead = $this->lead(['phone_normalized' => '+41791234567']);

        $this->actingAs($this->t['admin'])
            ->getJson("/api/v1/leads/{$lead->uuid}")
            ->assertOk()
            ->assertJsonPath('data.lead.language', null);
    }
}
