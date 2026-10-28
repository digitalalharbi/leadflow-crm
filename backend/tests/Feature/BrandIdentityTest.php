<?php

namespace Tests\Feature;

use App\Domain\Mail\EmailDeliveries;
use App\Domain\Mail\OutboundEmail;
use App\Models\EmailDelivery;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The server's side of the identity: email subjects and frames, the error pages a browser sees, the favicon. */
class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subject_uses_the_dash_never_a_pipe_and_names_the_product_once(): void
    {
        $this->assertSame('التقرير الأسبوعي — مهتم', Brand::subject('التقرير الأسبوعي'));
        $this->assertSame('التقرير الأسبوعي — مهتم', Brand::subject('التقرير الأسبوعي | مهتم'));
        $this->assertSame('Weekly Report — Mohtam', Brand::subject('Weekly Report | Mohtam'));
        $this->assertSame('مهتم — حوّل الاهتمام إلى فرصة', Brand::subject(''));
        $this->assertSame('التقارير — مهتم', Brand::title('التقارير'));
    }

    public function test_every_queued_email_carries_the_branded_subject_and_frame(): void
    {
        $t = $this->provisionTenant('Brand Co');
        $html = view('emails.account-link', ['title' => 'دعوة', 'body' => 'مرحبًا', 'link' => 'https://mohtam.pro/i/x', 'button' => 'قبول', 'note' => '', 'workspace' => 'Brand Co', 'footer' => ''])->render();

        $this->asTenant($t['tenant'], fn () => app(EmailDeliveries::class)->queue(new OutboundEmail('a@b.test', null, 'دعوة | Brand Co', $html)));
        $delivery = EmailDelivery::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame('دعوة — Brand Co — مهتم', $delivery->subject);
        $this->assertStringNotContainsString(' | ', $delivery->subject);
        $this->assertStringContainsString('/brand/logo-email.png', $delivery->html);
        $this->assertStringContainsString('alt="مهتم"', $delivery->html);
        $this->assertStringContainsString('mohtam.pro', $delivery->html);
        $this->assertStringContainsString(Brand::TAGLINE_AR, $delivery->html);
    }

    public function test_a_browser_that_hits_a_missing_page_gets_the_branded_page_not_the_framework_one(): void
    {
        $response = $this->get('/f/'.str_repeat('Z', 40))->assertNotFound();

        $response->assertSee('مهتم', false)->assertSee('الصفحة غير موجودة', false)->assertSee('— مهتم</title>', false);
        $response->assertDontSee('Laravel')->assertDontSee('Not Found');
    }

    public function test_the_api_host_has_a_real_favicon_and_no_framework_welcome_page(): void
    {
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')), 'an empty favicon.ico is the browser globe');
        $this->assertFileDoesNotExist(resource_path('views/welcome.blade.php'));
    }
}
