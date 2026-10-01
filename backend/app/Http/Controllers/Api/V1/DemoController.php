<?php

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\IncomingLead;
use App\Enums\IntegrationProvider;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Leads\LeadIngestionService;
use App\Support\ApiResponse;
use Database\Seeders\Support\DemoContent;
use Illuminate\Http\Request;

/**
 * Injects a lead that looks like it just arrived from an ad campaign.
 *
 * Exists for one reason: during a sales demo you want to say "this lead just
 * came in from the Snapchat campaign" and have it appear in the CRM while the
 * prospect watches. It goes through the real ingestion pipeline — normalisation,
 * duplicate detection, assignment, activity log — so what they see is the
 * product working, not a mock.
 *
 * The route is registered only outside production (see routes/api.php), and this
 * controller refuses to run there as a second line of defence.
 */
class DemoController extends Controller
{
    public function __construct(private LeadIngestionService $ingestion) {}

    public function simulateLead(Request $request)
    {
        if (app()->environment('production')) {
            abort(404);
        }

        // Whoever is running the demo, not whoever can enter a lead: fabricated
        // customers are a presentation device, not a sales action.
        abort_unless($request->user()->isAdmin(), 403, 'This action is not allowed.');

        $data = $request->validate([
            'platform' => ['nullable', 'in:meta,snapchat,tiktok,google_ads,linkedin,website'],
            'count' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $tenant = $request->user()->tenant;
        $wanted = $data['platform'] ?? null;
        $count = (int) ($data['count'] ?? 1);

        $campaigns = collect(DemoContent::CAMPAIGNS)
            ->when($wanted, fn ($all) => $all->where(1, $wanted))
            ->values();

        if ($campaigns->isEmpty()) {
            $campaigns = collect(DemoContent::CAMPAIGNS);
        }

        $projects = Project::where('status', 'active')->pluck('uuid', 'id');
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $campaign = $campaigns->random();
            $first = DemoContent::FIRST_NAMES[array_rand(DemoContent::FIRST_NAMES)];
            $last = DemoContent::LAST_NAMES[array_rand(DemoContent::LAST_NAMES)];

            // A plausible unused Saudi mobile. Collisions simply re-engage the
            // existing lead, which is itself a good thing to show.
            $phone = '+9665'.random_int(10_000_000, 99_999_999);

            $result = $this->ingestion->ingest($tenant, IncomingLead::fromArray(
                IntegrationProvider::GenericWebhook,
                [
                    'full_name' => $first.' '.$last,
                    'phone' => $phone,
                    'city' => DemoContent::CITIES[array_rand(DemoContent::CITIES)],
                    'platform' => $campaign[1],
                    'source' => $campaign[4],
                    'campaign_name' => $campaign[0],
                    'adset_name' => $campaign[2],
                    'ad_name' => $campaign[3],
                    'form_name' => 'نموذج العرض',
                    'budget_max' => [900_000, 1_400_000, 2_200_000][random_int(0, 2)],
                    'preferred_property_type' => DemoContent::PROPERTY_TYPES[array_rand(DemoContent::PROPERTY_TYPES)],
                    'purchase_timeline' => 'within_month',
                    'payment_method' => random_int(0, 1) ? 'cash' : 'financing',
                    'project_id' => $projects->keys()->isEmpty() ? null : $projects->keys()->random(),
                ],
            ));

            if ($result->wasAccepted()) {
                $created[] = [
                    'id' => $result->lead->uuid,
                    'full_name' => $result->lead->full_name,
                    'phone' => $result->lead->phone_normalized,
                    'platform' => $result->lead->platform,
                    'campaign_name' => $result->lead->campaign_name,
                    'assigned_to' => $result->lead->assignee?->name,
                    'outcome' => $result->outcome,
                ];
            }
        }

        return ApiResponse::created([
            'leads' => $created,
            'message' => count($created).' lead(s) ingested through the live pipeline.',
        ]);
    }
}
