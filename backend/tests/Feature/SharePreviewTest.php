<?php

namespace Tests\Feature;

use App\Models\ReportShare;
use App\Models\TenantBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The card a pasted report link shows in WhatsApp and other chats. */
class SharePreviewTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Rawaq Real Estate');
        config(['integrations.app_url' => 'https://mohtam.pro']);
    }

    private function share(array $data = []): array
    {
        return $this->actingAs($this->t['admin'])->postJson('/api/v1/report-shares', $data + ['name' => 'تقرير مشروع النخيل', 'template' => 'client', 'days' => 14])
            ->assertCreated()->json('data.share');
    }

    private function preview(array $share)
    {
        return $this->get('/share-preview/'.basename($share['url']));
    }

    public function test_a_link_previews_as_its_own_report_from_its_own_company_with_no_figures(): void
    {
        $share = $this->share();
        $token = basename($share['url']);

        $html = $this->preview($share)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="تقرير مشروع النخيل — Rawaq Real Estate">', $html);
        $this->assertStringContainsString('آخر 14 يوماً', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://mohtam.pro/r/'.$token.'">', $html);
        $this->assertStringContainsString('https://mohtam.pro/brand/social-preview.png', $html, 'no logo of its own: the product card');
        $this->assertDoesNotMatchRegularExpression('/\d{3,}/', preg_replace('/'.preg_quote($token, '/').'/', '', $html), 'no figure is previewed');
    }

    public function test_a_company_with_a_logo_sends_it_and_a_hostile_name_cannot_break_out_of_the_page(): void
    {
        $this->asTenant($this->t['tenant'], fn () => TenantBranding::query()->update(['logo_url' => 'https://cdn.example-co.sa/logo.png']));
        $share = $this->share(['name' => '"><script>alert(1)</script>']);

        $html = $this->preview($share)->assertOk()->getContent();

        $this->assertStringContainsString('content="https://cdn.example-co.sa/logo.png"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_a_revoked_paused_or_unknown_link_previews_as_a_plain_card_and_is_never_counted(): void
    {
        $share = $this->share();
        $token = basename($share['url']);

        $this->actingAs($this->t['admin'])->postJson("/api/v1/report-shares/{$share['id']}/revoke")->assertOk();
        $revoked = $this->preview($share)->assertOk()->getContent();
        $unknown = $this->get('/share-preview/'.str_repeat('a', 40))->assertOk()->getContent();

        foreach ([$revoked, $unknown] as $html) {
            $this->assertStringContainsString('تقرير مشترك — مهتم', $html);
            $this->assertStringNotContainsString('تقرير مشروع النخيل', $html);
            $this->assertStringNotContainsString('Rawaq', $html);
        }

        $this->assertSame(0, (int) ReportShare::withoutGlobalScopes()->where('token_hash', ReportShare::hash($token))->value('view_count'), 'a preview is not a view');
    }
}
