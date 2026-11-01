<?php

namespace Tests\Feature;

use App\Domain\Mail\EmailDnsReadiness;
use App\Models\CommissioningRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The readiness screen can check email itself: credentials, a real message to the person checking, and the sending
 * domain's SPF/DKIM/DMARC. A run never makes the row live — only a customer opening a message does that.
 */
class EmailCommissioningTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Mail Co');
        // No network in tests: a domain with SPF and DMARC but no DKIM.
        $this->app->instance(EmailDnsReadiness::class, new EmailDnsReadiness(fn (string $host, int $type) => match (true) {
            $type === DNS_TXT && $host === 'example.test' => [['txt' => 'v=spf1 include:mail.test ~all']],
            $type === DNS_TXT && $host === '_dmarc.example.test' => [['txt' => 'v=DMARC1; p=none']],
            default => [],
        }));
    }

    public function test_without_a_mail_server_it_says_so(): void
    {
        $result = $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/email/commission')->assertOk()->json('data.result');

        $this->assertFalse($result['ok']);
        $this->assertSame([['key' => 'credentials', 'ok' => false, 'detail' => 'credentials_missing']], $result['steps']);
    }

    public function test_a_server_that_refuses_and_missing_dkim_both_show_and_nothing_turns_live(): void
    {
        $this->actingAs($this->t['admin'])->patchJson('/api/v1/integrations/email', [
            'host' => '127.0.0.1', 'port' => 1, 'username' => 'u', 'password' => 'p-p-p-p-p', 'from_address' => 'sales@example.test',
        ])->assertOk();

        $data = $this->actingAs($this->t['admin'])->postJson('/api/v1/readiness/email/commission')->assertOk()->json('data');
        $steps = collect($data['result']['steps'])->keyBy('key');

        $this->assertTrue($steps['credentials']['ok']);
        $this->assertFalse($steps['send']['ok']);
        $this->assertFalse($steps['dns']['ok']);
        $this->assertStringContainsString('dkim', $steps['dns']['detail']);
        $this->assertFalse($data['result']['ok']);
        $this->assertSame('email', CommissioningRun::latest('id')->first()->key);

        $row = collect($data['readiness']['items'])->firstWhere('key', 'email');
        $this->assertTrue($row['commissionable']);
        $this->assertNotSame('live_verified', $row['status']);
    }

    public function test_a_rep_cannot_run_it(): void
    {
        $this->actingAs($this->makeUser($this->t['tenant']))->postJson('/api/v1/readiness/email/commission')->assertForbidden();
    }
}
