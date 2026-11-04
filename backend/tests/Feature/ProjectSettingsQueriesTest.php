<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Asking each lead what to do next must not read its project once per lead, and must not hand the project's settings out. */
class ProjectSettingsQueriesTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider screens */
    public function test_project_settings_are_read_once_however_many_leads_are_shown(string $uri): void
    {
        $a = $this->provisionTenant('Alpha');
        $this->asTenant($a['tenant'], function () use ($a) {
            $project = Project::create(['name' => 'One', 'automation_settings' => ['active' => true]]);
            for ($i = 0; $i < 12; $i++) {
                Lead::create(['full_name' => "Lead {$i}", 'phone' => '05512345'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'project_id' => $project->id, 'assigned_to' => $a['admin']->id]);
            }
        });

        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (str_contains($query->sql, 'automation_settings') && str_contains($query->sql, 'limit 1')) {
                $reads++;
            }
        });

        $response = $this->actingAs($a['admin'])->getJson($uri)->assertOk();

        $this->assertSame(0, $reads);
        $this->assertStringNotContainsString('automation_settings', $response->getContent());
    }

    public static function screens(): array
    {
        return ['the lead list' => ['/api/v1/leads'], 'work now' => ['/api/v1/work/next']];
    }
}
