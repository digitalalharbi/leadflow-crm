<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\LeadEngagement;
use App\Models\ProjectFile;
use App\Models\Tenant;
use App\Services\Leads\LeadScoringService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The link a customer opens.
 *
 * Public, because it is sent to somebody who has no account and never will.
 * What stands in for authentication is the token: forty random characters,
 * issued per lead, and worth exactly one project's brochure if it leaks.
 *
 * The open is recorded before the file is served, and a failure to record must
 * never stop the file being served — a customer who clicked a link in a
 * WhatsApp message should get the brochure whatever our tables are doing.
 */
class SharedFileController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request, string $token): StreamedResponse
    {
        // No tenant context on a public route: the token establishes it.
        $share = $this->context->withoutScope(
            fn () => FileShare::where('token', $token)->first()
        );

        abort_if($share === null, 404);

        $file = $this->context->withoutScope(
            fn () => ProjectFile::where('id', $share->project_file_id)->first()
        );

        abort_if($file === null || ! $file->is_active, 404);

        $this->record($share);

        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->name, [
            // Inline rather than an attachment: a brochure opened in the phone's
            // own viewer is read, and one that lands in Downloads is not.
            'Content-Disposition' => 'inline; filename="'.addslashes($file->name).'"',
        ]);
    }

    private function record(FileShare $share): void
    {
        try {
            /*
             * Inside the share's own workspace, not merely outside the scope.
             *
             * BelongsToTenant refuses to create a row with no tenant context —
             * correctly — so writing the engagement unscoped fails, and the
             * catch below would have swallowed it: every open served the file
             * and recorded nothing, which is indistinguishable from nobody
             * opening anything.
             */
            $tenant = Tenant::withoutGlobalScopes()->findOrFail($share->tenant_id);

            $this->context->withTenant($tenant, function () use ($share) {
                $share->forceFill([
                    'opens' => $share->opens + 1,
                    'first_opened_at' => $share->first_opened_at ?? now(),
                    'last_opened_at' => now(),
                ])->save();

                LeadEngagement::create([
                    'lead_id' => $share->lead_id,
                    'type' => LeadEngagement::FILE_OPENED,
                    'meta' => ['file' => $share->project_file_id, 'channel' => $share->channel],
                    'happened_at' => now(),
                ]);

                // The open has to move the score now, not whenever somebody next
                // edits the lead — a hot lead is hot while the brochure is open.
                $lead = Lead::find($share->lead_id);
                $lead && app(LeadScoringService::class)->refresh($lead, 'engagement');
            });
        } catch (\Throwable $e) {
            /*
             * Deliberately swallowed. A customer who clicked a link in a
             * WhatsApp message should get the brochure whatever our tables are
             * doing, and an engagement we failed to record is a score that is
             * slightly low — not a door that will not open.
             */
            report($e);
        }
    }
}
