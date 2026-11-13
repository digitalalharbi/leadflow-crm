<?php

namespace App\Http\Controllers;

use App\Models\ReportShare;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What WhatsApp, Telegram, Slack, LinkedIn and the like show when a shared report link is pasted into a chat.
 *
 * Those apps read a page's title and picture without running its JavaScript, and the app's own page is one static
 * shell, so every report link previewed as the same generic Mohtam card. The front door's proxy sends only those
 * crawlers here (never a person, never a search engine), and this answers with a small page carrying the report's own
 * name, the company that sent it and the period, with the company's logo when it has one.
 *
 * It says no more than the password screen already says to anyone holding the link: no figure, no client list, no
 * project name. A link that is revoked, expired, paused or unknown previews as a plain, unrevealing card, and is never
 * counted as a view.
 */
class SharePreviewController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(Request $request, string $token): Response
    {
        $share = $this->context->withoutScope(fn () => ReportShare::withoutGlobalScopes()->where('token_hash', ReportShare::hash($token))->first());

        $base = rtrim((string) config('integrations.app_url'), '/');
        $default = $base.'/brand/social-preview.png';
        $canonical = $base.'/r/'.$token;

        $usable = $share !== null && ! $share->isRevoked() && $share->archived_at === null && ! $share->isExpired() && $share->paused_at === null;

        if (! $usable) {
            $title = 'تقرير مشترك — مهتم';
            $description = 'هذا الرابط غير متاح الآن. تواصل مع من شاركه معك.';
            $image = $default;
        } else {
            $tenant = $this->context->withoutScope(fn () => Tenant::with('branding')->find($share->tenant_id));
            $company = $tenant?->branding?->company_name ?: $tenant?->name ?: 'مهتم';
            $logo = (string) $tenant?->branding?->logo_url;

            $title = $share->name.' — '.$company;
            $description = 'تقرير مباشر تتجدد أرقامه، آخر '.(int) ($share->days ?: 30).' يوماً. افتح الرابط لعرضه.';
            $image = preg_match('#^https://#i', $logo) === 1 ? $logo : $default;
        }

        $html = '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.e($title).'</title>'
            .'<meta property="og:type" content="website">'
            .'<meta property="og:site_name" content="مهتم">'
            .'<meta property="og:locale" content="ar_SA">'
            .'<meta property="og:title" content="'.e($title).'">'
            .'<meta property="og:description" content="'.e($description).'">'
            .'<meta property="og:url" content="'.e($canonical).'">'
            .'<meta property="og:image" content="'.e($image).'">'
            .'<meta name="twitter:card" content="summary_large_image">'
            .'<meta name="twitter:title" content="'.e($title).'">'
            .'<meta name="twitter:description" content="'.e($description).'">'
            .'<meta name="twitter:image" content="'.e($image).'">'
            // A person who somehow lands here is sent on to the real page.
            .'<meta http-equiv="refresh" content="0;url='.e($canonical).'">'
            .'</head><body><a href="'.e($canonical).'">'.e($title).'</a></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // Short, so a revoked or paused link stops previewing as the report quickly.
            'Cache-Control' => 'public, max-age=60',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
