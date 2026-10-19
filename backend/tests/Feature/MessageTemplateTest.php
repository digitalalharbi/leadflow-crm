<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saved messages, and the rule that protects the customer from them: a
 * placeholder we cannot fill is removed, never sent. A buyer who receives
 * "بخصوص اهتمامك بمشروع {{project}}" knows a machine wrote it and nobody read
 * it — which is worse than a shorter sentence.
 */
class MessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('Template Co');

        $this->lead = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عبدالله الكريم',
            'first_name' => 'عبدالله',
            'phone' => '0554445555',
            'phone_normalized' => '+966554445555',
        ]));
    }

    public function test_a_new_workspace_starts_with_usable_messages(): void
    {
        $response = $this->actingAs($this->t['admin'])->getJson('/api/v1/message-templates');

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('data.templates'));
        $this->assertSame(['name', 'agent', 'company', 'project', 'questions'], $response->json('data.variables'));
    }

    public function test_a_filled_template_reads_as_a_person_wrote_it(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'برج النخيل']));
        $this->lead->forceFill(['project_id' => $project->id])->saveQuietly();

        $body = $this->preview($this->template('مرحبًا {{name}}، معك {{agent}} من {{company}} بخصوص اهتمامك بمشروع {{project}}.'));

        $this->assertStringContainsString('عبدالله', $body);
        $this->assertStringContainsString('برج النخيل', $body);
        $this->assertStringContainsString($this->t['admin']->name, $body);
        $this->assertStringNotContainsString('{{', $body);
    }

    public function test_an_unfilled_placeholder_is_never_sent_to_a_customer(): void
    {
        // This lead has no project.
        $body = $this->preview($this->template('مرحبًا {{name}}، معك {{agent}} بخصوص اهتمامك بمشروع {{project}}.'));

        $this->assertStringNotContainsString('{{project}}', $body);
        $this->assertStringNotContainsString('{{', $body);
        // And the sentence does not end on a dangling preposition.
        $this->assertStringNotContainsString('بمشروع.', $body);
    }

    public function test_an_unknown_placeholder_is_stripped_too(): void
    {
        $body = $this->preview($this->template('مرحبًا {{name}}، رقم وحدتك {{unit_number}}.'));

        $this->assertStringNotContainsString('{{unit_number}}', $body);
        $this->assertStringNotContainsString('{{', $body);
    }

    public function test_sending_a_template_records_which_one_was_used(): void
    {
        $this->connectWhatsapp();
        $template = $this->template('مرحبًا {{name}}، نتواصل معك من {{company}}.');

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['template_uuid' => $template->uuid])
            ->assertStatus(201)
            ->assertJsonPath('data.message.template_name', $template->name);

        $this->assertDatabaseHas('lead_messages', [
            'lead_id' => $this->lead->id,
            'message_type' => 'template',
            'template_name' => $template->name,
        ]);
    }

    public function test_what_the_rep_typed_wins_over_the_template(): void
    {
        $this->connectWhatsapp();
        $template = $this->template('النص الأصلي {{name}}');

        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$this->lead->uuid}/messages", [
            'template_uuid' => $template->uuid,
            'body' => 'عدّلت الرسالة قبل الإرسال',
        ])->assertStatus(201);

        $this->assertDatabaseHas('lead_messages', ['body' => 'عدّلت الرسالة قبل الإرسال']);
        $this->assertDatabaseMissing('lead_messages', ['body' => 'النص الأصلي عبدالله']);
    }

    public function test_a_rep_may_read_the_saved_messages_but_not_rewrite_them(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/message-templates')->assertSuccessful();

        $this->actingAs($rep)->postJson('/api/v1/message-templates', [
            'name' => 'رسالتي', 'body' => 'نص',
        ])->assertStatus(403);

        $mine = $this->template('نص قابل للتعديل');

        $this->actingAs($rep)
            ->patchJson("/api/v1/message-templates/{$mine->uuid}", ['body' => 'نص معدّل'])
            ->assertStatus(403);

        $this->actingAs($rep)
            ->deleteJson("/api/v1/message-templates/{$mine->uuid}")
            ->assertStatus(403);
    }

    public function test_a_sales_manager_can_change_them(): void
    {
        // What a company says to its buyers is a management decision, not an
        // API-key decision — which is what tying it to the integration policy
        // had made it.
        $manager = $this->makeUser($this->t['tenant'], Role::Manager);

        $this->actingAs($manager)->postJson('/api/v1/message-templates', [
            'name' => 'رسالة المدير', 'body' => 'مرحبًا {{name}}',
        ])->assertStatus(201);
    }

    public function test_a_rep_is_not_shown_deactivated_messages(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);
        $retired = $this->template('رسالة متوقفة');
        $retired->update(['is_active' => false]);

        $names = collect($this->actingAs($rep)
            ->getJson('/api/v1/message-templates?all=1')
            ->json('data.templates'))->pluck('name');

        $this->assertFalse($names->contains($retired->name));

        // A manager editing the list does need to see them.
        $managerNames = collect($this->actingAs($this->t['admin'])
            ->getJson('/api/v1/message-templates?all=1')
            ->json('data.templates'))->pluck('name');

        $this->assertTrue($managerNames->contains($retired->name));
    }

    public function test_a_template_from_another_workspace_cannot_be_edited(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $theirs = $this->asTenant($other['tenant'], fn () => MessageTemplate::create([
            'name' => 'رسالتهم', 'body' => 'نصهم', 'channel' => 'whatsapp', 'is_active' => true,
        ]));

        $this->actingAs($this->t['admin'])
            ->patchJson("/api/v1/message-templates/{$theirs->uuid}", ['body' => 'مُخترق'])
            ->assertStatus(404);

        $this->assertSame('نصهم', $theirs->refresh()->body);
    }

    public function test_templates_do_not_cross_workspaces(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $mine = $this->template('رسالة خاصة بنا');

        $names = collect($this->actingAs($other['admin'])
            ->getJson('/api/v1/message-templates')
            ->json('data.templates'))->pluck('name');

        $this->assertFalse($names->contains($mine->name));
    }

    public function test_a_template_from_another_workspace_cannot_be_sent(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $theirs = $this->asTenant($other['tenant'], fn () => MessageTemplate::create([
            'name' => 'رسالتهم', 'body' => 'نصهم', 'channel' => 'whatsapp', 'is_active' => true,
        ]));

        $this->connectWhatsapp();

        $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages", ['template_uuid' => $theirs->uuid])
            ->assertStatus(422);

        $this->assertDatabaseMissing('lead_messages', ['body' => 'نصهم']);
    }

    public function test_a_lead_with_no_first_name_can_still_be_sent_a_template(): void
    {
        // The normal shape of a lead that arrived as a bare phone number, which
        // is exactly what a WhatsApp-first or minimal webhook lead looks like.
        $nameless = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => '0557778888',
            'phone' => '0557778888',
            'phone_normalized' => '+966557778888',
        ]));

        $template = $this->template('مرحبًا {{name}}، معك {{agent}} من {{company}}.');

        $body = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$nameless->uuid}/messages/preview", ['template_uuid' => $template->uuid])
            ->assertSuccessful()
            ->json('data.body');

        $this->assertStringNotContainsString('{{', $body);
        $this->assertNotEmpty($body);
    }

    public function test_a_single_word_name_is_used_whole(): void
    {
        $single = $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'نورة',
            'phone' => '0557779999',
            'phone_normalized' => '+966557779999',
        ]));

        $body = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$single->uuid}/messages/preview", [
                'template_uuid' => $this->template('مرحبًا {{name}}')->uuid,
            ])
            ->assertSuccessful()
            ->json('data.body');

        $this->assertStringContainsString('نورة', $body);
    }

    public function test_only_the_first_word_of_a_full_name_is_used(): void
    {
        // "مرحبًا عبدالله الكريم المحترم" reads as a form letter; a first name
        // reads as a person.
        $body = $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages/preview", [
                'template_uuid' => $this->template('مرحبًا {{name}}')->uuid,
            ])
            ->assertSuccessful()
            ->json('data.body');

        $this->assertStringContainsString('عبدالله', $body);
        $this->assertStringNotContainsString('الكريم', $body);
    }

    private function template(string $body): MessageTemplate
    {
        return $this->asTenant($this->t['tenant'], fn () => MessageTemplate::create([
            'name' => 'قالب '.substr(md5($body), 0, 6),
            'body' => $body,
            'channel' => 'whatsapp',
            'is_active' => true,
        ]));
    }

    private function preview(MessageTemplate $template): string
    {
        return $this->actingAs($this->t['admin'])
            ->postJson("/api/v1/leads/{$this->lead->uuid}/messages/preview", ['template_uuid' => $template->uuid])
            ->assertSuccessful()
            ->json('data.body');
    }

    private function connectWhatsapp(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => IntegrationProvider::Whatsapp,
            'name' => 'WhatsApp Business',
            'status' => Integration::CONFIGURATION_REQUIRED,
            'config' => ['phone_number_id' => '1888000111'],
            'credentials' => ['verify_token' => 'verify', 'app_secret' => 'secret-secret-secret'],
            'is_active' => true,
        ]));
    }

    public function test_a_message_can_be_written_for_one_project_language_and_stage(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'الواحة']));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/message-templates', [
            'name' => 'ترحيب الواحة', 'body' => 'أهلًا {{name}}', 'purpose' => 'welcome',
            'project_id' => $project->uuid, 'language' => 'ar', 'stage' => 'new', 'audience' => 'investment',
        ])->assertCreated()
            ->assertJsonPath('data.template.purpose', 'welcome')
            ->assertJsonPath('data.template.project_id', $project->uuid)
            ->assertJsonPath('data.template.project_name', 'الواحة')
            ->assertJsonPath('data.template.audience', 'investment');
    }

    public function test_a_project_from_another_workspace_cannot_be_targeted(): void
    {
        $other = $this->provisionTenant('Other Co');
        $theirs = $this->asTenant($other['tenant'], fn () => Project::create(['name' => 'سر']));

        $this->actingAs($this->t['admin'])->postJson('/api/v1/message-templates', [
            'name' => 'محاولة', 'body' => 'x', 'project_id' => $theirs->uuid,
        ])->assertNotFound();
    }
}
