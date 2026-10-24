<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The staging demo refresh (scripts/refresh-demo.sh) runs this seeder; it must run against the schema as it is now.
 */
class DemoSeederRunsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_runs_on_the_current_schema(): void
    {
        $this->fakeMailer();
        $this->seed(DemoSeeder::class);

        $this->assertGreaterThan(0, Tenant::count());
    }
}
