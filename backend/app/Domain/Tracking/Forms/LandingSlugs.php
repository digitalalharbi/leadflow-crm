<?php

namespace App\Domain\Tracking\Forms;

use App\Models\LandingForm;
use App\Models\TrackingLink;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The public address of a landing page: mohtam.pro/l/<slug>.
 *
 * One address space across every workspace, so uniqueness is checked without tenant scope (a deleted page keeps its slug: an
 * ad may still point there, and it must not start opening somebody else's page). It carries no tenant or project id. A slug
 * also may not equal a tracking link's token, because /l/<token> addresses are already printed on ads and QR codes.
 */
class LandingSlugs
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,62}[a-z0-9])$/';

    /** Words a public address must not be: they read as the product's own pages. */
    public const RESERVED = [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'contact', 'dashboard', 'features', 'files', 'help', 'home', 'journey',
        'landing', 'leads', 'login', 'logout', 'mohtam', 'new', 'null', 'preview', 'pricing', 'privacy', 'projects', 'public', 'register',
        'reports', 'root', 'security', 'settings', 'signup', 'static', 'support', 'system', 'terms', 'test', 'undefined', 'vision', 'www',
    ];

    private const ARABIC = [
        'ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ء' => '', 'ؤ' => 'o', 'ئ' => 'e', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'th', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh',
        'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'a',
    ];

    public function __construct(private TenantContext $context) {}

    /** A readable address from a name, in any language, or null if nothing usable remains. */
    public static function fromName(string $name): ?string
    {
        $latin = strtr(mb_strtolower($name), self::ARABIC);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $latin), '-');
        $slug = substr($slug, 0, 40);
        $slug = rtrim($slug, '-');

        return strlen($slug) >= 3 ? $slug : null;
    }

    /** Why this slug cannot be used: invalid | reserved | taken — null when it can. */
    public function problem(string $slug, ?int $exceptFormId = null): ?string
    {
        if (! preg_match(self::PATTERN, $slug)) {
            return 'invalid';
        }

        if (in_array($slug, self::RESERVED, true)) {
            return 'reserved';
        }

        return $this->context->withoutScope(function () use ($slug, $exceptFormId) {
            $form = LandingForm::withoutGlobalScopes()->where('slug', $slug)->when($exceptFormId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
            // An address a page once had stays that page's: an ad or a poster may still say it.
            $past = DB::table('landing_slug_history')->where('slug', $slug)->when($exceptFormId, fn ($q, $id) => $q->where('landing_form_id', '!=', $id))->exists();

            return $form || $past || TrackingLink::withoutGlobalScopes()->where('token', $slug)->exists() ? 'taken' : null;
        });
    }

    /** The first free address starting from a name. */
    public function suggest(string $name, ?int $exceptFormId = null): string
    {
        $base = self::fromName($name) ?? 'project';
        $slug = $base;

        for ($i = 2; $this->problem($slug, $exceptFormId) !== null; $i++) {
            $slug = $base.'-'.($i <= 50 ? $i : bin2hex(random_bytes(3)));
        }

        return $slug;
    }
}
