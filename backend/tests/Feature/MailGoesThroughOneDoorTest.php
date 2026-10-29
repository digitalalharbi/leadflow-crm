<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\EmailDelivery;
use App\Models\EmailDeliveryAttempt;
use App\Models\Lead;
use App\Models\LeadMessage;
use App\Notifications\ImportantNotification;
use App\Services\Email\EmailManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Every email the product sends — a report, a summary, an instant alert, a lead's message, the
 * test of a mail server — leaves through one door, App\Domain\Mail\MailerProvider. Nothing reaches
 * for Laravel's default mailer, and the door says honestly whether anything left the building.
 */
class MailGoesThroughOneDoorTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('One Door Co');
        config(['leadflow_mail.provider' => 'log']);
    }

    private function lead(): Lead
    {
        return $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'نورة', 'email' => 'noura@example.test', 'phone_normalized' => '+9665'.random_int(10000000, 99999999),
            'status' => 'new', 'assigned_to' => $this->t['admin']->id,
        ]));
    }

    public function test_no_code_reaches_for_laravels_mailer_outside_the_providers(): void
    {
        $offenders = [];
        $forbidden = ['Mail::to(', 'Mail::send(', 'Mail::raw(', 'Mail::html(', 'Mail::queue(', 'Mail::later(', 'Mail::mailer()', 'Mail::build(', 'extends Mailable', "'mail'"];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Domain/Mail/Providers/')) {
                continue;
            }

            $code = $file->getContents();

            foreach ($forbidden as $needle) {
                // The literal 'mail' is a column name in a few importers; only a notification channel named so is a door.
                if ($needle === "'mail'" && ! str_contains($file->getPathname(), '/Notifications/')) {
                    continue;
                }

                if (str_contains($code, $needle)) {
                    $offenders[] = str_replace(app_path().'/', '', $file->getPathname()).' uses '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders);
        $this->assertDirectoryDoesNotExist(app_path('Mail'), 'there are no Mailables left: a Mailable is a message for the default mailer');
    }

    public function test_a_leads_email_takes_the_product_door_and_says_mock_when_nothing_leaves(): void
    {
        $door = $this->fakeMailer();
        $lead = $this->lead();

        $this->assertSame('mock', app(EmailManager::class)->provider(), 'a door that only writes it down is not a transport');
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/emails", ['subject' => 'تفاصيل', 'body' => 'مرحبًا'])->assertCreated();

        $message = LeadMessage::withoutGlobalScopes()->where('channel', 'email')->latest('id')->firstOrFail();
        $this->assertSame('sent', $message->status->value);
        $this->assertSame('mock', $message->provider);
        $this->assertCount(1, $door->sent);
        $this->assertSame('noura@example.test', $door->sent[0]->toEmail);
        $this->assertSame($message->provider_message_id, $door->sent[0]->headers['Message-ID'], 'the id on the row is the one in the header');
        $this->assertSame('lead_message', $door->sent[0]->tags['kind']);
    }

    public function test_a_door_that_really_sends_is_the_platform(): void
    {
        $door = $this->fakeMailer(delivers: true);
        $lead = $this->lead();

        $this->assertSame('platform', app(EmailManager::class)->provider());
        $this->actingAs($this->t['admin'])->postJson("/api/v1/leads/{$lead->uuid}/emails", ['subject' => 'تفاصيل', 'body' => 'مرحبًا'])->assertCreated();

        $this->assertSame('platform', LeadMessage::withoutGlobalScopes()->where('channel', 'email')->latest('id')->value('provider'));
        $this->assertCount(1, $door->sent);
    }

    public function test_an_instant_alert_is_a_delivery_with_the_same_queue_log_and_honesty_as_a_report(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales);

        $rep->notify(new ImportantNotification('hot_lead', 'عميل ساخن', 'سلمان أصبح ساخنًا — اتصل به الآن.', 'abc-lead', null, 'critical'));

        $delivery = EmailDelivery::withoutGlobalScopes()->where('tenant_id', $this->t['tenant']->id)->where('kind', 'alert.hot_lead')->first();
        $this->assertNotNull($delivery, 'the alert went through the delivery queue');
        $this->assertSame($rep->email, $delivery->to_email);
        $this->assertSame('عميل ساخن — مهتم', $delivery->subject);
        $this->assertSame('logged', $delivery->status, 'the log provider never says sent');
        $this->assertSame('user', $delivery->subject_type);
        $this->assertSame($rep->id, $delivery->subject_id);
        $this->assertStringContainsString('/leads/abc-lead', $delivery->makeVisible('html')->html);
        $this->assertSame(1, EmailDeliveryAttempt::withoutGlobalScopes()->where('email_delivery_id', $delivery->id)->count(), 'one attempt on the failure log');

        // The delivery screen shows it beside the reports.
        $kinds = collect($this->actingAs($this->t['admin'])->getJson('/api/v1/email-deliveries')->assertOk()->json('data.deliveries'))->pluck('kind')->all();
        $this->assertContains('alert.hot_lead', $kinds);
    }

    public function test_the_workspaces_own_server_is_its_own_door_and_the_test_email_takes_it(): void
    {
        $registry = $this->fakeMailer(delivers: true);

        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p-p-p-p-p', 'from_address' => 'sales@example.test',
        ])->assertOk();

        $manager = app(EmailManager::class);
        $this->asTenant($this->t['tenant'], function () use ($manager) {
            $this->assertSame('smtp', $manager->provider());
            $this->assertSame('workspace_smtp', $manager->door()->key());
        });

        // Nothing answers on port 1: the door says so, the integration records the failure, and the product's provider was never asked.
        $this->actingAs($this->t['admin'])->postJson('/api/v1/integrations/email/test')->assertStatus(422);
        $this->assertSame([], $registry->sent, 'a workspace with its own server does not send through the platform');
    }
}
