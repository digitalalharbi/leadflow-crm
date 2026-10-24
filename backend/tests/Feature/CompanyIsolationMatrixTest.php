<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\TrackingLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Company A against Company B, across the whole API — not a sample of it.
 *
 * Company B is built out fully (projects, leads, follow-ups, visits, reservations, units, campaigns, tracking links,
 * partners, companies, files, people, invitations, the journey's rules and templates). Then Company A's people walk
 * every authenticated route the application has, every method, with every id Company B owns substituted into it, and
 * the lists and filters with B's ids as query parameters. Nothing of B may come back — not a name, not an id — and no
 * row of B may change. New routes are covered the day they are written, because the route table is read, not listed.
 */
class CompanyIsolationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private array $a;

    private array $b;

    /** Strings that exist only in Company B. */
    private array $markers = [];

    /** @var array<string, list<string>> table => B uuids */
    private array $bIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeMailer();
        Storage::fake('local');

        $this->a = $this->provisionTenant('Alpha Realty');
        $this->b = $this->provisionTenant('Beta Realty');
        $this->buildOut($this->a['tenant'], 'ALPHA');
        $this->buildOut($this->b['tenant'], 'BETAONLY');

        $this->markers = ['BETAONLY', 'Beta Realty', $this->b['admin']->email];

        // Every row Company B owns that has a public id, from every table that has both columns.
        $tables = collect(DB::select("select table_name from information_schema.columns where table_schema = 'public' and column_name = 'uuid'
            intersect select table_name from information_schema.columns where table_schema = 'public' and column_name = 'tenant_id'"))->pluck('table_name');

        foreach ($tables as $table) {
            $ids = DB::table($table)->where('tenant_id', $this->b['tenant']->id)->pluck('uuid')->map(fn ($u) => (string) $u)->all();

            if ($ids !== []) {
                $this->bIds[$table] = $ids;
                $this->markers = array_merge($this->markers, $ids);
            }
        }

        $this->markers[] = $this->b['tenant']->uuid;
    }

    private function buildOut(Tenant $tenant, string $tag): void
    {
        $this->asTenant($tenant, function () use ($tenant, $tag) {
            $project = Project::create(['name' => "{$tag} Tower"]);
            $rep = $this->makeUser($tenant, Role::Sales, ['name' => "{$tag} Rep", 'email' => strtolower($tag).'.rep@example.test']);
            $this->makeUser($tenant, Role::Manager, ['name' => "{$tag} Manager", 'email' => strtolower($tag).'.manager@example.test']);
            $this->makeUser($tenant, Role::MarketingManager, ['email' => strtolower($tag).'.mkt@example.test']);
            $this->makeUser($tenant, Role::SalesManager, ['email' => strtolower($tag).'.sm@example.test']);
            $lead = Lead::create(['full_name' => "{$tag} Lead", 'phone' => '0555'.random_int(100000, 999999), 'phone_normalized' => '+966555'.random_int(100000, 999999), 'project_id' => $project->id, 'assigned_to' => $rep->id, 'email' => strtolower($tag).'@lead.test']);
            FollowUp::create(['lead_id' => $lead->id, 'assigned_to' => $rep->id, 'due_at' => now()->addDay(), 'note' => "{$tag} call back", 'status' => 'pending']);
            $unit = Property::create(['project_id' => $project->id, 'code' => "{$tag}-101", 'title' => "{$tag} Unit", 'property_type' => 'apartment', 'status' => 'available']);
            Appointment::create(['lead_id' => $lead->id, 'project_id' => $project->id, 'assigned_to' => $rep->id, 'scheduled_at' => now()->addDays(2), 'status' => 'scheduled', 'notes' => "{$tag} visit"]);
            Reservation::create(['lead_id' => $lead->id, 'project_id' => $project->id, 'property_id' => $unit->id, 'status' => 'active', 'amount_minor' => 100000, 'reserved_at' => now(), 'notes' => "{$tag} hold"]);
            Campaign::create(['project_id' => $project->id, 'platform' => 'meta', 'name' => "{$tag} Campaign", 'origin' => 'manual', 'status' => 'active']);
            TrackingLink::create(['token' => Str::random(10), 'name' => "{$tag} Link", 'kind' => 'link', 'channel' => 'instagram', 'project_id' => $project->id, 'is_active' => true]);
            Partner::create(['name' => "{$tag} Broker", 'type' => 'broker', 'is_active' => true]);
            Company::create(['name' => "{$tag} Holding", 'is_active' => true]);
            Storage::disk('local')->put("tenant/{$tenant->id}/project-files/x/{$tag}.pdf", "%PDF {$tag}");
            ProjectFile::create(['project_id' => $project->id, 'name' => "{$tag} Brochure.pdf", 'kind' => 'brochure', 'path' => "tenant/{$tenant->id}/project-files/x/{$tag}.pdf", 'mime' => 'application/pdf', 'size_bytes' => 10, 'is_active' => true]);
        });
    }

    /** @return list<Route> */
    private function tenantRoutes(): array
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/')
                && in_array('auth:sanctum', $r->gatherMiddleware(), true)
                && ! str_starts_with($r->uri(), 'api/v1/super-admin')
                && ! str_starts_with($r->uri(), 'api/v1/platform/')
                && ! str_contains($r->uri(), 'logout'))
            ->values()->all();
    }

    private function assertNothingOfB(string $body, string $what): void
    {
        foreach ($this->markers as $marker) {
            $this->assertStringNotContainsString($marker, $body, "{$what} leaked Company B data ({$marker}).");
        }
    }

    private function fingerprintB(): string
    {
        $rows = [];

        foreach (array_keys($this->bIds) as $table) {
            $rows[$table] = DB::table($table)->where('tenant_id', $this->b['tenant']->id)->orderBy('id')->get()
                ->map(fn ($r) => collect((array) $r)->except(['updated_at', 'last_used_at', 'opens', 'last_opened_at'])->all())->all();
        }

        return md5(serialize($rows));
    }

    public function test_every_route_refuses_every_id_company_b_owns_and_changes_nothing_of_b(): void
    {
        $before = $this->fingerprintB();
        $allIds = array_merge(...array_values($this->bIds));
        $checked = 0;

        foreach ([$this->a['admin'], User::where('email', 'alpha.manager@example.test')->first(), User::where('email', 'alpha.rep@example.test')->first()] as $actor) {
            foreach ($this->tenantRoutes() as $route) {
                $params = $route->parameterNames();

                if ($params === []) {
                    continue;
                }

                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    foreach ($allIds as $id) {
                        $uri = '/'.preg_replace('/\{[^}]+\}/', $id, $route->uri());
                        $response = $this->actingAs($actor)->json($method, $uri, ['name' => 'Hijack', 'full_name' => 'Hijack', 'status' => 'lost', 'is_active' => false]);

                        $this->assertFalse($response->isSuccessful() && $response->getStatusCode() !== 204 && $this->leaks($response->getContent()),
                            "{$method} {$route->uri()} answered {$response->getStatusCode()} with Company B's data for {$actor->email}.");
                        $this->assertNothingOfB((string) $response->getContent(), "{$method} {$uri} as {$actor->email}");
                        $checked++;
                    }
                }
            }
        }

        $this->assertSame($before, $this->fingerprintB(), 'A request from Company A changed a row of Company B.');
        $this->assertGreaterThan(1000, $checked);
    }

    public function test_every_list_and_filter_shows_only_company_a(): void
    {
        $filters = array_filter([
            'project_id' => $this->bIds['projects'][0] ?? null,
            'assigned_to' => User::where('email', 'betaonly.rep@example.test')->value('uuid'),
            'campaign_id' => $this->bIds['campaigns'][0] ?? null,
            'partner_id' => $this->bIds['partners'][0] ?? null,
            'search' => 'BETAONLY',
            'q' => 'BETAONLY',
        ]);

        foreach ([$this->a['admin'], User::where('email', 'alpha.mkt@example.test')->first(), User::where('email', 'alpha.sm@example.test')->first()] as $actor) {
            foreach ($this->tenantRoutes() as $route) {
                if ($route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
                    continue;
                }

                $uri = '/'.$route->uri();
                $this->assertNothingOfB((string) $this->actingAs($actor)->getJson($uri)->getContent(), "GET {$uri} as {$actor->email}");
                $this->assertNothingOfB((string) $this->actingAs($actor)->getJson($uri.'?'.http_build_query($filters))->getContent(), "GET {$uri} filtered by B ids as {$actor->email}");
            }
        }
    }

    public function test_a_company_header_naming_company_bs_holding_is_refused(): void
    {
        $holding = $this->bIds['companies'][0];

        $this->actingAs($this->a['admin'])->withHeader('X-Company-Id', $holding)->getJson('/api/v1/leads')->assertForbidden();
    }

    public function test_company_bs_files_never_open_for_company_a(): void
    {
        $file = ProjectFile::withoutGlobalScopes()->where('tenant_id', $this->b['tenant']->id)->first();
        $projectB = Project::withoutGlobalScopes()->find($file->project_id);

        $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$projectB->uuid}/files/{$file->uuid}/link")->assertNotFound();

        // A link signed for A's tenant cannot be bent to B's file, and an unsigned or altered one is refused.
        $mine = ProjectFile::withoutGlobalScopes()->where('tenant_id', $this->a['tenant']->id)->first();
        $projectA = Project::withoutGlobalScopes()->find($mine->project_id);
        $url = $this->actingAs($this->a['admin'])->getJson("/api/v1/projects/{$projectA->uuid}/files/{$mine->uuid}/link")->assertOk()->json('data.url');

        $this->get($url)->assertOk();
        $this->get(str_replace($mine->uuid, $file->uuid, $url))->assertForbidden();
        $this->get(str_replace('tenant='.$this->a['tenant']->id, 'tenant='.$this->b['tenant']->id, $url))->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();

        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_new_files_are_stored_under_their_companys_own_prefix(): void
    {
        $project = $this->asTenant($this->a['tenant'], fn () => Project::first());

        $this->actingAs($this->a['admin'])->post("/api/v1/projects/{$project->uuid}/files", [
            'file' => UploadedFile::fake()->create('plan.pdf', 20, 'application/pdf'), 'kind' => 'brochure',
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = ProjectFile::withoutGlobalScopes()->latest('id')->value('path');
        $this->assertStringStartsWith('tenant/'.$this->a['tenant']->id.'/project-files/', $path);
    }

    private function leaks(string $body): bool
    {
        foreach ($this->markers as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }
}
