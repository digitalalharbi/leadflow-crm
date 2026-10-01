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
        $this->assertSame(['name', 'agent', 'company', 'project'], $response->json('data.variables'));
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

    public function test_only_an_admin_can_change_the_saved_messages(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $this->actingAs($rep)->getJson('/api/v1/message-templates')->assertSuccessful();
        $this->actingAs($rep)->postJson('/api/v1/message-templates', [
            'name' => 'رسالتي', 'body' => 'نص',
        ])->assertStatus(403);
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
}
