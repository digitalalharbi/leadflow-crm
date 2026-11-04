<?php

namespace Tests\Feature;

use App\Domain\Mail\EmailDnsReadiness;
use Tests\TestCase;

/** "Ready" only when SPF, DKIM and DMARC are really published — read from DNS, never assumed. */
class EmailDnsReadinessTest extends TestCase
{
    private function readiness(array $records): EmailDnsReadiness
    {
        return new EmailDnsReadiness(fn (string $host, int $type) => $records[$host][$type] ?? []);
    }

    public function test_nothing_published_is_not_ready_and_says_what_is_missing(): void
    {
        $result = $this->readiness([])->check('mohtam.pro', 'resend');

        $this->assertFalse($result['ready']);
        $this->assertSame('missing', $result['spf']['status']);
        $this->assertSame('missing', $result['dkim']['status']);
        $this->assertSame('missing', $result['dmarc']['status']);
        $this->assertSame('missing', $result['mx']['status']);
    }

    public function test_all_three_published_is_ready(): void
    {
        $result = $this->readiness([
            'mohtam.pro' => [DNS_TXT => [['txt' => 'v=spf1 include:amazonses.com ~all']], DNS_MX => [['target' => 'feedback-smtp.amazonses.com']]],
            '_dmarc.mohtam.pro' => [DNS_TXT => [['txt' => 'v=DMARC1; p=quarantine; rua=mailto:info@mohtam.pro']]],
            'resend._domainkey.mohtam.pro' => [DNS_TXT => [['txt' => 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQ']]],
        ])->check('mohtam.pro', 'resend');

        $this->assertTrue($result['ready']);
    }

    public function test_two_spf_records_is_an_error_and_no_selector_is_unknown_not_ok(): void
    {
        $result = $this->readiness([
            'mohtam.pro' => [DNS_TXT => [['txt' => 'v=spf1 a ~all'], ['txt' => 'v=spf1 mx ~all']]],
        ])->check('mohtam.pro', null);

        $this->assertSame('error', $result['spf']['status']);
        $this->assertSame('unknown', $result['dkim']['status']);
        $this->assertFalse($result['ready']);
    }

    public function test_with_no_selector_named_a_key_at_a_providers_own_selector_is_found(): void
    {
        config(['mail.dkim_selector' => null]);

        $result = (new EmailDnsReadiness(function (string $host, int $type) {
            return match (true) {
                $type === DNS_TXT && $host === 'mohtam.pro' => [['txt' => 'v=spf1 include:_spf.mail.hostinger.com ~all']],
                $type === DNS_TXT && $host === '_dmarc.mohtam.pro' => [['txt' => 'v=DMARC1; p=none']],
                $type === DNS_CNAME && $host === 'hostingermail-a._domainkey.mohtam.pro' => [['target' => 'hostingermail-a.dkim.mail.hostinger.com']],
                default => [],
            };
        }))->check('mohtam.pro');

        $this->assertSame('ok', $result['dkim']['status']);
        $this->assertTrue($result['ready']);
    }
}
