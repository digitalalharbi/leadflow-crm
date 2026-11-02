<?php

namespace Tests\Feature;

use App\Enums\IntegrationProvider;
use App\DataTransferObjects\IncomingLead;
use App\Models\Integration;
use App\Models\Project;
use App\Models\SheetSource;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** What a first real client would have run into: a new company's leads with no owner, sources that could not name a project, click ids lost on the way in. */
class FirstClientGapsTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMailer();
        $this->t = $this->provisionTenant('Alpha Realty');
    }

    public function test_a_company_made_on_the_console_shares_out_its_leads_from_the_first_one(): void
    {
        $owner = $this->makePlatformOwner();
        $token = $owner->createToken('platform', ['platform'], now()->addHour())->plainTextToken;

        $made = $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/api/v1/super-admin/tenants', [
            'company_name' => 'Gamma', 'admin_name' => 'Owner', 'admin_email' => 'owner@gamma.test',
        ]);
        $made->assertCreated();

        $tenant = Tenant::whereHas('branding', fn ($q) => $q->where('company_name', 'Gamma'))->firstOrFail();
        $this->assertSame('round_robin', $tenant->settings()->withoutGlobalScopes()->first()->assignment_mode);
    }

    public function test_a_webhooks_click_id_counts_whatever_the_platform_calls_it(): void
    {
        $id = fn (array $data, array $raw = []) => IncomingLead::fromArray(IntegrationProvider::GenericWebhook, ['phone' => '0500000000'] + $data, $raw)->clickId;

        $this->assertSame('snap', $id(['ScCid' => 'snap']));
        $this->assertSame('snap', $id([], ['scCid' => 'snap']));
        $this->assertSame('ms', $id(['msclkid' => 'ms']));
        $this->assertSame('given', $id(['click_id' => 'given', 'gclid' => 'g']));
        $this->assertNull($id(['campaign_click' => 'not-one-of-them']));
    }

    public function test_the_public_api_keeps_a_click_id(): void
    {
        $key = $this->actingAs($this->t['admin'])->postJson('/api/v1/api-keys', ['name' => 'Site', 'scopes' => ['leads:write']])->json('data.plain');
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$key])->postJson('/api/v1/public/leads', ['full_name' => 'Rana', 'phone' => '0501234567', 'ScCid' => 'snap-1'])->assertSuccessful();

        $this->asTenant($this->t['tenant'], fn () => $this->assertDatabaseHas('leads', ['tenant_id' => $this->t['tenant']->id, 'click_id' => 'snap-1']));
    }

    public function test_a_file_of_leads_can_belong_to_a_project_and_only_one_the_person_can_open(): void
    {
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $elsewhere = $this->provisionTenant('Beta Realty');
        $foreign = $this->asTenant($elsewhere['tenant'], fn () => Project::create(['name' => 'Theirs']));

        $upload = function () {
            $file = UploadedFile::fake()->createWithContent('leads.csv', "name,phone\nRana,0501234567\n");

            return $this->actingAs($this->t['admin'])->post('/api/v1/imports', ['file' => $file], ['Accept' => 'application/json'])->assertSuccessful()->json('data.import.id');
        };

        $id = $upload();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$id}/process", ['mapping' => ['name' => 'full_name', 'phone' => 'phone'], 'project_id' => $foreign->uuid])->assertNotFound();
        $this->actingAs($this->t['admin'])->postJson("/api/v1/imports/{$id}/process", ['mapping' => ['name' => 'full_name', 'phone' => 'phone'], 'project_id' => $project->uuid])->assertOk();

        $this->assertDatabaseHas('leads', ['tenant_id' => $this->t['tenant']->id, 'full_name' => 'Rana', 'project_id' => $project->id]);
    }

    public function test_a_sheets_default_project_is_found_in_the_company_or_refused(): void
    {
        $elsewhere = $this->provisionTenant('Beta Realty');
        $foreign = $this->asTenant($elsewhere['tenant'], fn () => Project::create(['name' => 'Theirs']));
        $mine = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'Mine']));
        $source = $this->asTenant($this->t['tenant'], fn () => SheetSource::create(['integration_id' => Integration::create(['provider' => IntegrationProvider::GoogleSheets, 'name' => 'Sheets', 'is_active' => true])->id, 'spreadsheet_id' => 'x', 'sheet_name' => 'Leads', 'is_active' => true]));

        $this->actingAs($this->t['admin'])->patchJson("/api/v1/integrations/sheets/{$source->uuid}", ['defaults' => ['project_id' => $foreign->id]])->assertUnprocessable();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/integrations/sheets/{$source->uuid}", ['defaults' => ['project_id' => $foreign->uuid]])->assertUnprocessable();
        $this->actingAs($this->t['admin'])->patchJson("/api/v1/integrations/sheets/{$source->uuid}", ['defaults' => ['project_id' => $mine->uuid]])->assertOk();

        $this->assertSame($mine->id, $source->fresh()->defaults['project_id']);
    }
}
