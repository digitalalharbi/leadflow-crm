<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Query count and response time for the endpoints that carry the product,
 * measured through the real middleware and controller stack. Dispatching into
 * the kernel rather than over HTTP keeps the numbers about our code instead of
 * the dev server's single-threaded socket handling.
 */
class MeasurePerformance extends Command
{
    protected $signature = 'leadflow:measure {--tenant=loadtest}';

    protected $description = 'Measure query counts and response times for the main endpoints';

    public function handle(TenantContext $context): int
    {
        $tenant = $context->withoutScope(fn () => Tenant::where('slug', $this->option('tenant'))->first());

        if (! $tenant) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $admin = User::where('tenant_id', $tenant->id)->where('role', 'admin')->firstOrFail();
        $token = $admin->createToken('measure')->plainTextToken;
        $leads = DB::table('leads')->where('tenant_id', $tenant->id)->count();

        $sample = DB::table('leads')->where('tenant_id', $tenant->id)->first();

        $cases = [
            'Leads, page 1' => '/api/v1/leads',
            'Leads, page 100' => '/api/v1/leads?page=100',
            'Leads, search by name' => '/api/v1/leads?search='.rawurlencode(mb_substr($sample->full_name, 0, 4)),
            'Leads, search by phone' => '/api/v1/leads?search='.rawurlencode($sample->phone),
            'Leads, status filter' => '/api/v1/leads?status[]=new&status[]=contacted',
            'Leads, combined filters' => '/api/v1/leads?status[]=new&temperature[]=hot&platform[]=meta',
            'Leads, overdue' => '/api/v1/leads?overdue=1',
            'Leads, malformed filter' => '/api/v1/leads?project_id=not-a-uuid',
            'Dashboard' => '/api/v1/dashboard',
            'Pipeline' => '/api/v1/pipeline',
            'Follow-ups, overdue' => '/api/v1/follow-ups?bucket=overdue',
            'Follow-ups, today' => '/api/v1/follow-ups?bucket=today',
            'Report, by platform' => '/api/v1/reports?type=platform',
            'Report, by campaign' => '/api/v1/reports?type=campaign',
            'Report, by sales rep' => '/api/v1/reports?type=sales_rep',
            'Report, by status' => '/api/v1/reports?type=status',
            'Users' => '/api/v1/users',
            'Projects' => '/api/v1/projects',
        ];

        /*
         * The screens that look at everything a lead has ever done are the ones
         * that degrade first, so the busiest lead in the tenant is measured
         * rather than an average one.
         */
        $busiest = DB::table('lead_messages')
            ->where('tenant_id', $tenant->id)
            ->select('lead_id', DB::raw('count(*) as total'))
            ->groupBy('lead_id')
            ->orderByDesc('total')
            ->first();

        if ($busiest) {
            $uuid = DB::table('leads')->where('id', $busiest->lead_id)->value('uuid');
            $messages = (int) $busiest->total;
            $activities = DB::table('lead_activities')->where('lead_id', $busiest->lead_id)->count();

            $cases["Lead detail ({$messages} msg, {$activities} act)"] = '/api/v1/leads/'.$uuid;
            $cases['Conversation, page 1'] = '/api/v1/leads/'.$uuid.'/messages';
            $cases['Conversation, last page'] = '/api/v1/leads/'.$uuid.'/messages?page=4';
            $cases['Qualification breakdown'] = '/api/v1/leads/'.$uuid.'/qualification';
        }

        $cases['Campaign quality'] = '/api/v1/reports/campaign-quality';
        $cases['Sales performance'] = '/api/v1/reports/performance';

        $this->line(sprintf('Tenant [%s] — %s leads', $tenant->slug, number_format($leads)));
        $this->newLine();
        $this->line(sprintf('| %-28s | %7s | %8s | %8s | %4s |', 'Endpoint', 'Queries', 'Time', 'Payload', 'Code'));
        $this->line('| '.str_repeat('-', 28).' | '.str_repeat('-', 7).' | '.str_repeat('-', 8).' | '.str_repeat('-', 8).' | '.str_repeat('-', 4).' |');

        foreach ($cases as $label => $uri) {
            $this->dispatchOnce($uri, $token);                 // warm caches
            [$queries, $ms, $bytes, $status] = $this->dispatchOnce($uri, $token);

            $this->line(sprintf(
                '| %-28s | %7d | %5.0f ms | %5.0f KB | %4d |',
                $label, $queries, $ms, $bytes / 1024, $status,
            ));
        }

        return self::SUCCESS;
    }

    /** @return array{0:int,1:float,2:int,3:int} */
    private function dispatchOnce(string $uri, string $token): array
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $request = Request::create($uri, 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $start = microtime(true);
        $response = app(Kernel::class)->handle($request);
        $ms = (microtime(true) - $start) * 1000;

        return [$queries, $ms, strlen($response->getContent()), $response->getStatusCode()];
    }
}
