<?php

namespace App\Console\Commands;

use App\Models\LostReason;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\Support\DemoContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bulk-loads a tenant with a realistic lead volume so query behaviour can be
 * measured against something the product will actually meet, not 40 rows.
 *
 * Uses raw inserts rather than Eloquent: the point is to produce the data fast,
 * not to exercise the model layer.
 */
class SeedLoadTest extends Command
{
    protected $signature = 'leadflow:seed-load-test {--leads=10000} {--tenant=loadtest}';

    protected $description = 'Bulk-load a tenant with a large lead volume for performance testing';

    public function handle(TenantContext $context): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to generate load-test data in production.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
        $total = (int) $this->option('leads');

        $tenant = $context->withoutScope(fn () => Tenant::where('slug', $slug)->first());

        if (! $tenant) {
            $this->error("No tenant with slug [{$slug}]. Provision it first.");

            return self::FAILURE;
        }

        $context->withTenant($tenant, function () use ($tenant, $total) {
            $userIds = User::where('tenant_id', $tenant->id)->pluck('id')->all();
            $projectIds = Project::pluck('id')->all();
            $reasonIds = LostReason::pluck('id')->all();

            if ($userIds === [] || $projectIds === []) {
                $this->error('The tenant needs users and projects first.');

                return;
            }

            $statuses = ['new', 'contacted', 'qualified', 'appointment', 'won', 'lost'];
            $temperatures = ['hot', 'warm', 'cold'];

            $existing = DB::table('leads')->where('tenant_id', $tenant->id)->count();
            $bar = $this->output->createProgressBar($total);
            $now = now();

            // Chunked inserts keep memory flat and the transaction short.
            foreach (array_chunk(range(1, $total), 1000) as $chunk) {
                $rows = [];

                foreach ($chunk as $i) {
                    $n = $existing + $i;
                    $campaign = DemoContent::CAMPAIGNS[$i % count(DemoContent::CAMPAIGNS)];
                    $status = $statuses[$i % 6];
                    $createdAt = $now->copy()->subMinutes($i * 3);

                    $rows[] = [
                        'uuid' => (string) Str::uuid(),
                        'tenant_id' => $tenant->id,
                        'assigned_to' => $userIds[$i % count($userIds)],
                        'project_id' => $projectIds[$i % count($projectIds)],
                        'first_name' => DemoContent::FIRST_NAMES[$i % count(DemoContent::FIRST_NAMES)],
                        'last_name' => DemoContent::LAST_NAMES[$i % count(DemoContent::LAST_NAMES)],
                        'full_name' => DemoContent::FIRST_NAMES[$i % count(DemoContent::FIRST_NAMES)]
                            .' '.DemoContent::LAST_NAMES[$i % count(DemoContent::LAST_NAMES)].' '.$n,
                        'phone' => '+9665'.str_pad((string) (90_000_000 + $n), 8, '0', STR_PAD_LEFT),
                        'phone_normalized' => '+9665'.str_pad((string) (90_000_000 + $n), 8, '0', STR_PAD_LEFT),
                        'email' => 'load'.$n.'@example.com',
                        'city' => DemoContent::CITIES[$i % count(DemoContent::CITIES)],
                        'platform' => $campaign[1],
                        'source' => $campaign[4],
                        'campaign_name' => $campaign[0],
                        'adset_name' => $campaign[2],
                        'ad_name' => $campaign[3],
                        'status' => $status,
                        'temperature' => $temperatures[$i % 3],
                        'score' => 20 + ($i % 80),
                        'budget_min' => 500_000 + (($i % 10) * 200_000),
                        'budget_max' => 900_000 + (($i % 10) * 200_000),
                        'preferred_property_type' => DemoContent::PROPERTY_TYPES[$i % count(DemoContent::PROPERTY_TYPES)],
                        'purchase_timeline' => DemoContent::TIMELINES[$i % count(DemoContent::TIMELINES)],
                        'next_follow_up_at' => $i % 4 === 0 ? $now->copy()->subDays($i % 7) : null,
                        'lost_reason_id' => $status === 'lost' && $reasonIds !== [] ? $reasonIds[$i % count($reasonIds)] : null,
                        'lost_at' => $status === 'lost' ? $createdAt : null,
                        'won_at' => $status === 'won' ? $createdAt : null,
                        'provider' => 'generic_webhook',
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];
                }

                DB::table('leads')->insert($rows);
                $bar->advance(count($chunk));
            }

            $bar->finish();
            $this->newLine(2);
            $this->info('Total leads in tenant: '.DB::table('leads')->where('tenant_id', $tenant->id)->count());
        });

        return self::SUCCESS;
    }
}
