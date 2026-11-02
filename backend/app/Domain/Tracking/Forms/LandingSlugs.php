<?php

namespace App\Domain\Tracking\Forms;

use App\Models\LandingForm;
use App\Models\TrackingLink;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The public address of a landing page: mohtam.pro/l/<slug>.
 *
 * One address space across every workspace, held from the moment a page is created, draft or not. `landing_slug_claims` is what
 * makes that true: its unique index decides who got an address, so `problem()` only says why not, early and politely. A claim is
 * never given up (a deleted, archived or renamed page keeps it: an ad may still point there, and it must not start opening
 * somebody else's page). An address carries no tenant or project id, and nothing inside the product finds a page by it. A slug also
 * may not equal a tracking link's token, because /l/<token> addresses are already printed on ads and QR codes.
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
            $past = DB::table('landing_slug_claims')->where('slug', $slug)->when($exceptFormId, fn ($q, $id) => $q->where('landing_form_id', '!=', $id))->exists();

            return $form || $past || TrackingLink::withoutGlobalScopes()->where('token', $slug)->exists() ? 'taken' : null;
        });
    }

    /** The first free address starting from a name: the name, then name-2, name-3… The same state always gives the same answer. */
    public function suggest(string $name, ?int $exceptFormId = null): string
    {
        $base = self::fromName($name) ?? 'project';
        $slug = $base;

        for ($i = 2; $this->problem($slug, $exceptFormId) !== null; $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /**
     * Take an address for this page. False means somebody else holds it, even if they got it a moment after `problem()` said it
     * was free: a second request waits here for the first to finish and then finds it gone, without failing its transaction.
     */
    public function claim(LandingForm $form, string $slug): bool
    {
        $row = ['slug' => $slug, 'tenant_id' => $form->tenant_id, 'landing_form_id' => $form->id, 'created_at' => now(), 'updated_at' => now()];

        return DB::table('landing_slug_claims')->insertOrIgnore($row) === 1
            || DB::table('landing_slug_claims')->where('slug', $slug)->where('landing_form_id', $form->id)->exists();
    }

    /** Give a page without an address the first free one from its project's name, or its own. */
    public function assign(LandingForm $form): string
    {
        $name = (string) ($form->project?->name ?: $form->name);

        do {
            $slug = $this->suggest($name, $form->id);
        } while (! $this->claim($form, $slug));

        $form->forceFill(['slug' => $slug])->save();

        return $slug;
    }

    /** Refuse an address, with the nearest one that would work. */
    public function refuse(string $slug, string $problem, ?int $exceptFormId = null): ValidationException
    {
        return ValidationException::withMessages(['slug' => [$problem]] + ($problem === 'invalid' ? [] : ['slug_suggestion' => [$this->suggest($slug, $exceptFormId)]]));
    }
}
