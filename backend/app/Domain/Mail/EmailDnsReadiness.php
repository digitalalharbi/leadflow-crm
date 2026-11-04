<?php

namespace App\Domain\Mail;

/**
 * Whether the sending domain publishes what receiving servers check before they trust our mail: SPF (who may send),
 * DKIM (the signature) and DMARC (what to do when either fails). Read from live DNS every time — never assumed — so
 * "ready" only ever means the records are really there.
 *
 * DKIM lives under a selector the mail provider chooses; MAIL_DKIM_SELECTOR names it (Resend: `resend`, Google:
 * `google`). Without one, DKIM is reported as unknown rather than guessed.
 */
class EmailDnsReadiness
{
    /** @var callable(string, int): array */
    private $lookup;

    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? fn (string $host, int $type) => @dns_get_record($host, $type) ?: [];
    }

    /** @return array{domain: string, spf: array, dkim: array, dmarc: array, mx: array, ready: bool} */
    public function check(?string $domain = null, ?string $selector = null): array
    {
        $domain = strtolower(trim($domain ?? $this->domainOf((string) config('leadflow_mail.from.address'))));
        $selector = $selector ?? (config('mail.dkim_selector') ?: null);

        $spf = $this->txt($domain, 'v=spf1');
        $dmarc = $this->txt('_dmarc.'.$domain, 'v=DMARC1');
        $dkim = $selector === null ? $this->anyKnownDkim($domain) : $this->dkim($selector.'._domainkey.'.$domain);
        $mx = ($this->lookup)($domain, DNS_MX);

        $mxStatus = $mx !== []
            ? ['status' => 'ok', 'detail' => collect($mx)->pluck('target')->filter()->implode(', ')]
            : ['status' => 'missing', 'detail' => 'No MX record: replies to '.$domain.' cannot be received.'];

        return [
            'domain' => $domain,
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
            'mx' => $mxStatus,
            'ready' => $spf['status'] === 'ok' && $dkim['status'] === 'ok' && $dmarc['status'] === 'ok',
        ];
    }

    private function txt(string $host, string $prefix): array
    {
        $records = collect(($this->lookup)($host, DNS_TXT))->map(fn ($r) => $r['txt'] ?? implode('', $r['entries'] ?? []))
            ->filter(fn ($txt) => is_string($txt) && stripos($txt, $prefix) === 0)->values();

        return match (true) {
            $records->isEmpty() => ['status' => 'missing', 'detail' => "No {$prefix} record at {$host}."],
            $records->count() > 1 => ['status' => 'error', 'detail' => "More than one {$prefix} record at {$host}; receivers reject that."],
            default => ['status' => 'ok', 'detail' => $records->first()],
        };
    }

    /** With no selector named, a key found at a selector a provider really uses is a key. Finding none is unknown, not missing. */
    private function anyKnownDkim(string $domain): array
    {
        foreach ((array) config('mail.dkim_selectors') as $candidate) {
            $found = $this->dkim($candidate.'._domainkey.'.$domain);

            if ($found['status'] === 'ok') {
                return $found;
            }
        }

        return ['status' => 'unknown', 'detail' => 'Set MAIL_DKIM_SELECTOR to the selector your mail provider gives you.'];
    }

    private function dkim(string $host): array
    {
        $txt = collect(($this->lookup)($host, DNS_TXT))->map(fn ($r) => $r['txt'] ?? implode('', $r['entries'] ?? []))
            ->first(fn ($t) => is_string($t) && str_contains($t, 'p='));
        $cname = collect(($this->lookup)($host, DNS_CNAME))->pluck('target')->first();

        return match (true) {
            $txt !== null => ['status' => 'ok', 'detail' => 'Public key published at '.$host.'.'],
            $cname !== null => ['status' => 'ok', 'detail' => "Delegated to {$cname}."],
            default => ['status' => 'missing', 'detail' => "No DKIM key at {$host}."],
        };
    }

    private function domainOf(string $address): string
    {
        return str_contains($address, '@') ? substr($address, strrpos($address, '@') + 1) : 'mohtam.pro';
    }
}
