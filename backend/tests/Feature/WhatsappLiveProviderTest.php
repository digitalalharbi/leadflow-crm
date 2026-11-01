<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationHealthReport;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\RetryWhatsappMessage;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\Whatsapp\WhatsappManager;
use App\Services\Whatsapp\WhatsappSender;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * What Meta actually receives.
 *
 * Everything else in the WhatsApp suite runs against the mock, which accepts
 * whatever it is given — so a template sent with the wrong shape, or a document
 * posted without its upload, passes all of it and fails on the first real lead.
 * These run the live provider with the Graph API faked at the HTTP boundary and
 * assert on the requests, which is the closest to the real thing that does not
 * need a real Meta account.
 *
 * They prove the request is well-formed. They do not prove Meta accepts it —
 * only a delivery receipt from a real number does, and the health report says
 * so until one has arrived.
 */
class WhatsappLiveProviderTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        $this->t = $this->provisionTenant('Live Co');

        $this->asTenant($this->t['tenant'], fn () => Integration::create([
            'provider' => 'whatsapp',
            'name' => 'WhatsApp Business',
            'status' => Integration::CONNECTED,
            'config' => ['phone_number_id' => '1555000999'],
            'credentials' => ['access_token' => 'EAAB-test', 'verify_token' => 'v', 'app_secret' => 's'],
            'is_active' => true,
        ]));
    }

    private function lead(array $attributes = []): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create(array_merge([
            'full_name' => 'سلمان القحطاني',
            'phone' => '0551234567',
            'phone_normalized' => '+9665512'.self::uniqueDigits(5),
        ], $attributes)));
    }

    private function openWindow(Lead $lead): Lead
    {
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Inbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud',
            'provider_message_id' => 'wamid.in.'.uniqid(),
            'message_type' => 'text',
            'body' => 'مرحبا',
            'status' => MessageStatus::Received,
        ]));

        return $lead;
    }

    private function saved(array $attributes = []): MessageTemplate
    {
        return $this->asTenant($this->t['tenant'], fn () => MessageTemplate::create(array_merge([
            'name' => 'عرض الاستثمار',
            'body' => 'مرحبًا {{name}}، تفاصيل {{project}} جاهزة.',
            'is_active' => true,
        ], $attributes)));
    }

    private function sender(): WhatsappSender
    {
        return app(WhatsappSender::class);
    }

    private function sendSaved(Lead $lead, MessageTemplate $template): LeadMessage
    {
        return $this->asTenant($this->t['tenant'], fn () => $this->sender()->sendSaved($lead, $template));
    }

    private function metaAccepts(): void
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA-77']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.REAL1']]]),
        ]);
    }

    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->all();
    }

    // -------------------------------------------------------- the shapes

    public function test_inside_the_window_a_saved_message_goes_as_text(): void
    {
        $this->metaAccepts();
        $lead = $this->openWindow($this->lead());

        $message = $this->sendSaved($lead, $this->saved());

        $this->assertSame(MessageStatus::Sent, $message->status);

        $request = $this->sent()[0];
        $this->assertSame('text', $request['type']);
        $this->assertSame('whatsapp', $request['messaging_product']);
        // Without the leading plus, which is the shape Meta wants.
        $this->assertStringStartsWith('9665512', $request['to']);
    }

    public function test_outside_the_window_it_goes_as_the_approved_template(): void
    {
        $this->metaAccepts();
        $lead = $this->lead(['full_name' => 'نورة العتيبي']);
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        $this->asTenant($this->t['tenant'], fn () => $lead->forceFill(['project_id' => $project->id])->save());

        $template = $this->saved([
            'meta_template_name' => 'investment_intro_ar',
            'meta_language' => 'ar',
            'meta_parameters' => ['name', 'project'],
        ]);

        $message = $this->sendSaved($lead->refresh(), $template);

        $this->assertSame(MessageStatus::Sent, $message->status);

        $request = $this->sent()[0];
        $this->assertSame('template', $request['type']);
        $this->assertSame('investment_intro_ar', $request['template']['name']);
        $this->assertSame('ar', $request['template']['language']['code']);

        // Variables in the order the template declares them, as Meta fills {{1}} and {{2}}.
        $parameters = $request['template']['components'][0]['parameters'];
        $this->assertSame('body', $request['template']['components'][0]['type']);
        $this->assertSame('نورة', $parameters[0]['text']);
        $this->assertSame('النرجس', $parameters[1]['text']);

        // The recipe is stored, so a retry is the same message.
        $this->assertSame('template', $message->send_spec['kind']);
    }

    public function test_a_cold_message_with_no_approved_template_is_refused_before_meta_is_asked(): void
    {
        Http::fake();
        $lead = $this->lead();

        try {
            $this->sendSaved($lead, $this->saved());
            $this->fail('a cold free-text send was attempted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('24-hour', $e->getMessage());
        }

        Http::assertNothingSent();
        // And nothing was written to the customer's history as a failed contact.
        $this->assertSame(0, LeadMessage::withoutGlobalScopes()->where('lead_id', $lead->id)->count());
    }

    /**
     * Meta rejects an empty parameter outright.
     *
     * A lead with no usable name cannot receive a template that opens with one,
     * and the right answer is to say so — not to send a blank and collect a
     * code nobody here can read.
     */
    public function test_a_template_that_needs_a_value_the_lead_lacks_is_refused(): void
    {
        Http::fake();
        // A bare phone number, which is not a name.
        $lead = $this->lead(['full_name' => '0551234567']);

        $template = $this->saved([
            'meta_template_name' => 'intro_ar',
            'meta_language' => 'ar',
            'meta_parameters' => ['name'],
        ]);

        try {
            $this->sendSaved($lead, $template);
            $this->fail('a template was sent with an empty parameter');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_brochure_is_uploaded_and_sent_by_media_id_not_by_link(): void
    {
        $this->metaAccepts();
        $lead = $this->openWindow($this->lead());
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));

        Storage::disk('local')->put('project-files/b.pdf', '%PDF-1.4 brochure');

        $file = $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $project->id,
            'name' => 'كتيب النرجس.pdf',
            'kind' => 'brochure',
            'path' => 'project-files/b.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 17,
        ]));

        $message = $this->asTenant($this->t['tenant'], fn () => $this->sender()->sendDocument($lead, $file, 'https://app.test/f/abc'));

        $this->assertSame(MessageStatus::Sent, $message->status);

        $requests = $this->sent();
        $this->assertCount(2, $requests, 'the upload and the send are two calls');

        $this->assertStringContainsString('/media', $requests[0]->url());
        $this->assertStringContainsString('/messages', $requests[1]->url());

        $document = $requests[1]['document'];

        // By id. A public link would make Meta's own servers fetch the file,
        // which would register as the customer opening it.
        $this->assertSame('MEDIA-77', $document['id']);
        $this->assertArrayNotHasKey('link', $document);
        $this->assertSame('كتيب النرجس.pdf', $document['filename']);
        $this->assertSame('https://app.test/f/abc', $document['caption']);
    }

    public function test_a_failed_upload_is_a_failed_message_not_a_lost_one(): void
    {
        Http::fake(['graph.facebook.com/*/media' => Http::response(
            ['error' => ['code' => 131053, 'message' => 'Media upload error']], 400,
        )]);

        $lead = $this->openWindow($this->lead());
        $project = $this->asTenant($this->t['tenant'], fn () => Project::create(['name' => 'النرجس']));
        Storage::disk('local')->put('project-files/b.pdf', 'x');

        $file = $this->asTenant($this->t['tenant'], fn () => ProjectFile::create([
            'project_id' => $project->id, 'name' => 'b.pdf', 'kind' => 'brochure',
            'path' => 'project-files/b.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1,
        ]));

        $message = $this->asTenant($this->t['tenant'], fn () => $this->sender()->sendDocument($lead, $file));

        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('131053', $message->failure_code);
    }

    // ----------------------------------------------------------- retries

    /**
     * The retry is the same message.
     *
     * It used to resend the body as text for as long as text was the only kind.
     * A template retried that way is refused outside the window; a brochure
     * retried that way delivers its caption and no file.
     */
    public function test_a_retried_template_is_still_a_template(): void
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::sequence()
                ->push(['error' => ['code' => 131016, 'message' => 'Service unavailable']], 503)
                ->push(['messages' => [['id' => 'wamid.RETRY']]]),
        ]);

        $lead = $this->lead(['full_name' => 'نورة العتيبي']);
        $template = $this->saved([
            'meta_template_name' => 'intro_ar',
            'meta_language' => 'ar',
            'meta_parameters' => ['name'],
        ]);

        $message = $this->sendSaved($lead, $template);

        $this->assertSame(MessageStatus::Pending, $message->status);
        Queue::assertPushed(RetryWhatsappMessage::class);

        (new RetryWhatsappMessage($message->id))->handle(
            app(WhatsappManager::class),
            app(TenantContext::class),
        );

        $requests = $this->sent();

        $this->assertCount(2, $requests);
        $this->assertSame('template', $requests[1]['type'], 'the retry degraded into plain text');
        $this->assertSame('intro_ar', $requests[1]['template']['name']);

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.RETRY', $message->provider_message_id);
    }

    // ---------------------------------------------- what counts as proof

    public function test_mock_traffic_never_counts_as_verification(): void
    {
        $this->asTenant($this->t['tenant'], function () {
            $integration = Integration::where('provider', 'whatsapp')->first();
            // No live credentials: a workspace that has never reached Meta.
            $integration->forceFill(['credentials' => ['verify_token' => 'v', 'app_secret' => 's']])->save();
        });

        $lead = $this->lead();

        // A delivered receipt, from the mock. Exactly what dev traffic leaves behind.
        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => 'mock',
            'provider_message_id' => 'wamid.mock.1',
            'message_type' => 'text',
            'body' => 'x',
            'status' => MessageStatus::Delivered,
        ]));

        $row = $this->asTenant($this->t['tenant'], function () {
            return collect(app(IntegrationHealthReport::class)->all())
                ->firstWhere('key', 'whatsapp');
        });

        $this->assertSame('implemented', $row['verification']);
        $this->assertSame('credentials_missing', $row['blocker']);
    }

    public function test_a_real_receipt_from_a_live_workspace_does_count(): void
    {
        $lead = $this->lead();

        $this->asTenant($this->t['tenant'], fn () => LeadMessage::create([
            'lead_id' => $lead->id,
            'direction' => MessageDirection::Outbound,
            'channel' => MessageChannel::Whatsapp,
            'provider' => 'whatsapp_cloud',
            'provider_message_id' => 'wamid.REAL',
            'message_type' => 'text',
            'body' => 'x',
            'status' => MessageStatus::Delivered,
        ]));

        $row = $this->asTenant($this->t['tenant'], function () {
            return collect(app(IntegrationHealthReport::class)->all())
                ->firstWhere('key', 'whatsapp');
        });

        $this->assertSame('externally_verified', $row['verification']);
        $this->assertNull($row['blocker']);
    }
}
