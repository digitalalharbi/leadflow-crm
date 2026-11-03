<?php

namespace App\Http\Controllers;

use App\Models\TrackingLink;
use App\Support\TenantContext;

/**
 * The script a landing page embeds: `<script src=".../tracker/{token}.js" defer></script>`.
 *
 * Small, dependency-free and honest about what it does: it keeps the visitor id the link handed
 * over, reports the page view, taps on WhatsApp and phone links and the form being started and
 * sent, and fills any form field named `visitor_id` / `tracking_token` so the lead that comes out
 * of the form is stitched to the trail that led to it.
 */
class TrackerScriptController extends Controller
{
    public function __invoke(TenantContext $context, string $token)
    {
        $link = $context->withoutScope(fn () => TrackingLink::withoutGlobalScopes()->where('token', $token)->first());

        abort_if($link === null || ! $link->isLive(), 404);

        $endpoint = rtrim((string) config('app.url'), '/').'/api/v1/track/'.$link->token;
        $js = <<<'JS'
(function () {
  var ENDPOINT = %s, TOKEN = %s, KEY = 'mt_vid';
  var qs = new URLSearchParams(location.search);
  var clickIds = {};
  qs.forEach(function (value, name) { clickIds[name.toLowerCase()] = value; });
  var vid = qs.get('mt_vid');
  try { if (vid) localStorage.setItem(KEY, vid); else vid = localStorage.getItem(KEY); } catch (e) {}
  function send(event, meta) {
    var body = { event: event, visitor_id: vid || undefined, url: location.href, referrer: document.referrer || undefined,
      utm_source: qs.get('utm_source') || undefined, utm_medium: qs.get('utm_medium') || undefined, utm_campaign: qs.get('utm_campaign') || undefined,
      utm_term: qs.get('utm_term') || undefined, utm_content: qs.get('utm_content') || undefined,
      click_id: clickIds.gclid || clickIds.fbclid || clickIds.ttclid || clickIds.sccid || undefined, meta: meta };
    var data = JSON.stringify(body);
    if (navigator.sendBeacon) { navigator.sendBeacon(ENDPOINT, new Blob([data], { type: 'text/plain' })); }
    else { fetch(ENDPOINT, { method: 'POST', body: data, keepalive: true, mode: 'no-cors', headers: { 'Content-Type': 'text/plain' } }); }
  }
  function fill(form) {
    ['visitor_id', 'tracking_token', 'referrer', 'landing_url'].forEach(function (name) {
      var el = form.querySelector('[name="' + name + '"]'); if (!el) return;
      el.value = name === 'visitor_id' ? (vid || '') : name === 'tracking_token' ? TOKEN : name === 'referrer' ? document.referrer : location.href;
    });
  }
  send('visit');
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href]'); if (!a) return;
    var href = a.getAttribute('href') || '';
    if (/^tel:/i.test(href)) send('call_click');
    else if (/(wa\.me|api\.whatsapp\.com)/i.test(href)) send('whatsapp_click');
  }, true);
  var started = false;
  document.addEventListener('focusin', function (e) {
    var f = e.target.form; if (!f || started) return; started = true; fill(f); send('form_start');
  });
  document.addEventListener('submit', function (e) { fill(e.target); send('form_submit'); }, true);
  window.mahtam = { track: send, visitorId: function () { return vid; } };
})();
JS;

        return response(sprintf($js, json_encode($endpoint, JSON_UNESCAPED_SLASHES), json_encode($link->token)), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
