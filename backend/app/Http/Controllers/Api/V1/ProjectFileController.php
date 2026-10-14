<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\FileForTheLead;
use App\Domain\Files\ShareFile;
use App\Http\Controllers\Controller;
use App\Models\FileShare;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The files a project sells with.
 *
 * Uploaded once against the project, tagged with what they are and who they are
 * for, and from then on chosen by the product rather than hunted for by a rep.
 */
class ProjectFileController extends Controller
{
    /** 20MB. A brochure larger than this is one nobody opens on a phone. */
    private const MAX_KB = 20480;

    public function __construct(
        private readonly FileForTheLead $chooser,
        private readonly ShareFile $sharer,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $project);

        $files = ProjectFile::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();

        return ApiResponse::success([
            'files' => $files->map(fn (ProjectFile $file) => $this->present($file))->values()->all(),
            'kinds' => ProjectFile::KINDS,
        ]);
    }

    public function store(Request $request, string $uuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KB, 'mimes:pdf,png,jpg,jpeg,webp,doc,docx,ppt,pptx,xls,xlsx'],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'kind' => ['required', Rule::in(ProjectFile::KINDS)],
            // Null is a real answer: a price list of numbers suits everybody,
            // and demanding a translation would leave half the customers
            // without one at all.
            'language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        $upload = $request->file('file');

        /*
         * On the private disk, always. These are a customer's own sales
         * material and occasionally their price list; the only way out is a
         * share link issued against one lead.
         */
        $path = $upload->store('project-files/'.$project->uuid, 'local');

        $file = ProjectFile::create([
            'project_id' => $project->id,
            'uploaded_by' => $request->user()->id,
            'name' => ($data['name'] ?? null) ?: $upload->getClientOriginalName(),
            'kind' => $data['kind'],
            'language' => $data['language'] ?? null,
            'path' => $path,
            'mime' => $upload->getClientMimeType(),
            'size_bytes' => $upload->getSize(),
            'sort' => $data['sort'] ?? 0,
            /*
             * Named even though the column defaults to it. A database default
             * never reaches a model created without mentioning the column, so
             * the row that goes back to the browser carried `is_active: null` —
             * and the screen read that as paused and offered to resume a file
             * it had just uploaded and which was, in the database, active.
             */
            'is_active' => true,
        ]);

        $this->audit->log('project_file.uploaded', $request->user(), $project, [
            'file' => $file->name,
            'kind' => $file->kind,
        ]);

        return ApiResponse::created(['file' => $this->present($file)]);
    }

    public function update(Request $request, string $uuid, string $fileUuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $file = ProjectFile::where('project_id', $project->id)->where('uuid', $fileUuid)->firstOrFail();

        $file->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(ProjectFile::KINDS)],
            'language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]));

        return ApiResponse::success(['file' => $this->present($file->refresh())]);
    }

    public function destroy(Request $request, string $uuid, string $fileUuid)
    {
        $project = Project::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $project);

        $file = ProjectFile::where('project_id', $project->id)->where('uuid', $fileUuid)->firstOrFail();

        /*
         * The row goes and the bytes go with it, but the shares stay: a lead's
         * history should still say a brochure was sent and opened, even after
         * somebody replaced it with a newer one.
         */
        Storage::disk('local')->delete($file->path);
        $file->delete();

        $this->audit->log('project_file.deleted', $request->user(), $project, ['file' => $file->name]);

        return ApiResponse::success(null, 'File removed.');
    }

    /**
     * What this product would send this lead, and why.
     *
     * Offered to the screen as a suggestion rather than applied silently: the
     * rep sees the chosen file and the reason before they send it, which is
     * what makes an automatic choice something they will leave switched on.
     */
    public function forLead(Request $request, string $leadUuid)
    {
        $lead = Lead::where('uuid', $leadUuid)->firstOrFail();
        $this->authorize('view', $lead);

        $choice = $this->chooser->pick($lead, $request->string('kind')->toString() ?: null);

        return ApiResponse::success([
            'suggested' => $choice['file'] === null ? null : $this->present($choice['file']),
            'reason' => $choice['reason'],
            'available' => $this->chooser->available($lead)
                ->map(fn (ProjectFile $file) => $this->present($file))
                ->values()
                ->all(),
        ]);
    }

    /** Issue the link for one lead, and tell the caller where it points. */
    public function share(Request $request, string $leadUuid)
    {
        $lead = Lead::where('uuid', $leadUuid)->firstOrFail();
        $this->authorize('update', $lead);

        $data = $request->validate([
            'file' => ['required', 'string', 'size:36'],
            'channel' => ['sometimes', Rule::in(['whatsapp', 'email', 'manual'])],
        ]);

        $file = ProjectFile::where('uuid', $data['file'])
            ->where('project_id', $lead->project_id)
            ->where('is_active', true)
            ->firstOrFail();

        $share = $this->sharer->forLead($file, $lead, $request->user(), $data['channel'] ?? 'manual');

        return ApiResponse::success(['share' => [
            'id' => $share->uuid,
            'url' => $share->url(),
            'opens' => $share->opens,
            'file' => $this->present($file),
        ]]);
    }

    /** What this lead has been sent, and what they opened. */
    public function sharesFor(Request $request, string $leadUuid)
    {
        $lead = Lead::where('uuid', $leadUuid)->firstOrFail();
        $this->authorize('view', $lead);

        $shares = FileShare::with('file')->where('lead_id', $lead->id)->latest('id')->get();

        return ApiResponse::success([
            'shares' => $shares->map(fn (FileShare $share) => [
                'id' => $share->uuid,
                'channel' => $share->channel,
                'opens' => $share->opens,
                'first_opened_at' => $share->first_opened_at?->toIso8601String(),
                'last_opened_at' => $share->last_opened_at?->toIso8601String(),
                'file' => $share->file ? $this->present($share->file) : null,
            ])->values()->all(),
        ]);
    }

    private function present(ProjectFile $file): array
    {
        return [
            'id' => $file->uuid,
            'name' => $file->name,
            'kind' => $file->kind,
            'language' => $file->language,
            'mime' => $file->mime,
            'size_bytes' => $file->size_bytes,
            'is_active' => $file->is_active,
            'sort' => $file->sort,
        ];
    }
}
