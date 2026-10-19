<?php

namespace App\Domain\Sheets;

use Illuminate\Support\Str;

/**
 * Reads a sheet's header row and decides which column is which.
 *
 * Every lead sheet is different: Meta writes `phone_number`, TikTok writes
 * `Phone number`, Snapchat `Phone Number`, and a team that set the sheet up by
 * hand writes `رقم الجوال`. A fixed schema would refuse two of the three
 * sheets this workspace is about to connect, so the mapping is worked out from
 * what is actually there:
 *
 *   1. **Aliases.** Each field knows the names it goes by, in the exports of
 *      every platform and in Arabic and English.
 *   2. **Normalisation.** Case, spacing, punctuation, Arabic letter forms and
 *      diacritics are folded away before comparing, so `Phone-Number ` and
 *      `phone_number` are the same header, and so are `الإيميل` and `الايميل`.
 *   3. **Confidence.** An exact alias is certain; a header that merely
 *      contains one is likely; a header that says nothing but whose values all
 *      look like phone numbers is a fair guess. Each mapping carries its score.
 *   4. **Ambiguity, not a guess.** Two columns that both look like the phone
 *      (`phone` and `whatsapp_number`) are reported as a choice for a person,
 *      with the likelier one used meanwhile — and a person's choice, once made,
 *      is never overwritten by the next guess.
 *
 * Whatever is not a known field is still somebody's answer — "which unit
 * type", "when do you want to buy" — and becomes a custom field rather than
 * being dropped.
 */
class ColumnMapper
{
    /** Below this a mapping is used but flagged for a look. */
    public const CONFIDENT = 0.75;

    /** Two candidates closer than this are a choice, not a winner. */
    private const AMBIGUITY_GAP = 0.1;

    /**
     * The names each field goes by, already in normalised form.
     *
     * Ordered by how certain the name is: the first entries are the platform
     * exports' own column names.
     */
    private const ALIASES = [
        'lead_id' => ['lead_id', 'leadid', 'id', 'lead_number', 'رقم_العميل', 'معرف_العميل'],
        'submitted_at' => ['created_time', 'create_time', 'created_at', 'submitted_at', 'submission_time', 'timestamp', 'date', 'lead_created_time', 'تاريخ', 'التاريخ', 'وقت_التسجيل', 'تاريخ_التسجيل'],
        'full_name' => ['full_name', 'fullname', 'name', 'customer_name', 'client_name', 'lead_name', 'الاسم', 'الاسم_الكامل', 'اسم_العميل', 'اسمك', 'الاسم_الثلاثي'],
        'first_name' => ['first_name', 'firstname', 'given_name', 'الاسم_الاول'],
        'last_name' => ['last_name', 'lastname', 'surname', 'family_name', 'اسم_العائله', 'الاسم_الاخير', 'العائله'],
        'phone' => ['phone_number', 'phone', 'mobile', 'mobile_number', 'phone_no', 'cell', 'tel', 'telephone', 'whatsapp', 'whatsapp_number', 'contact_number', 'رقم_الجوال', 'الجوال', 'رقم_الهاتف', 'الهاتف', 'الموبايل', 'رقم_الموبايل', 'واتساب', 'رقم_الواتساب', 'رقم_التواصل'],
        'email' => ['email', 'email_address', 'e_mail', 'mail', 'work_email', 'البريد', 'البريد_الالكتروني', 'الايميل', 'الاميل', 'الإيميل'],
        'city' => ['city', 'town', 'location', 'المدينه', 'المنطقه', 'الموقع', 'مدينتك'],
        'project' => ['project', 'project_name', 'development', 'property', 'المشروع', 'اسم_المشروع'],
        'campaign_id' => ['campaign_id', 'campaignid', 'معرف_الحمله'],
        'campaign_name' => ['campaign_name', 'campaign', 'utm_campaign', 'الحمله', 'اسم_الحمله'],
        'adset_id' => ['adset_id', 'ad_set_id', 'ad_group_id', 'adgroup_id', 'ad_squad_id', 'adsquad_id'],
        'adset_name' => ['adset_name', 'ad_set_name', 'ad_set', 'adset', 'ad_group_name', 'ad_group', 'adgroup_name', 'ad_squad_name', 'ad_squad', 'المجموعه_الاعلانيه'],
        'ad_id' => ['ad_id', 'adid', 'creative_id'],
        'ad_name' => ['ad_name', 'ad', 'creative_name', 'creative', 'الاعلان', 'اسم_الاعلان'],
        'form_id' => ['form_id', 'formid', 'lead_form_id', 'instant_form_id'],
        'form_name' => ['form_name', 'form', 'lead_form_name', 'lead_form', 'النموذج', 'اسم_النموذج'],
        'platform' => ['platform', 'network', 'channel_platform', 'المنصه'],
        'source' => ['source', 'utm_source', 'lead_source', 'channel', 'المصدر'],
        'notes' => ['notes', 'note', 'comments', 'comment', 'message', 'ملاحظات', 'ملاحظه', 'الرساله'],
    ];

    /**
     * Fields where a header that merely *contains* the alias is not evidence:
     * `id` is inside `campaign_id`, and `ad` is inside half the export.
     */
    private const EXACT_ONLY = ['id', 'ad', 'name', 'date', 'form', 'mail', 'cell', 'tel', 'channel', 'source', 'location', 'property'];

    /**
     * @param  array<int, string>  $headers  the header row, as written
     * @param  array<int, array<int, string>>  $sample  a few data rows, for value sniffing
     * @param  array<string, string|null>  $overrides  field => header a person chose (null: "none of them")
     * @return array{
     *   mapping: array<string, string>,
     *   confidence: array<string, float>,
     *   ambiguous: array<int, array{field: string, candidates: array<int, string>}>,
     *   custom: array<string, string>,
     *   usable: bool,
     * }
     */
    public function map(array $headers, array $sample = [], array $overrides = []): array
    {
        $headers = array_values(array_filter(array_map(fn ($h) => trim((string) $h), $headers), fn ($h) => $h !== ''));

        /** @var array<string, array<string, float>> $scores field => header => confidence */
        $scores = [];

        foreach ($headers as $index => $header) {
            $normal = self::normalise($header);

            foreach (self::ALIASES as $field => $aliases) {
                $score = $this->score($normal, $aliases);

                if ($score > 0) {
                    $scores[$field][$header] = max($scores[$field][$header] ?? 0, $score);
                }
            }

            // The values speak when the header does not.
            $values = array_map(fn (array $row) => trim((string) ($row[$index] ?? '')), $sample);
            $sniffed = $this->sniff($values);

            if ($sniffed !== null && ($scores[$sniffed][$header] ?? 0) < 0.8) {
                $scores[$sniffed][$header] = max($scores[$sniffed][$header] ?? 0, 0.8);
            }
        }

        $mapping = [];
        $confidence = [];
        $ambiguous = [];
        $taken = [];

        // The most certain fields choose first, so `phone_number` is claimed by
        // the phone before anything that merely resembles it gets a look.
        uksort($scores, fn ($a, $b) => max($scores[$b]) <=> max($scores[$a]));

        foreach ($scores as $field => $candidates) {
            if (array_key_exists($field, $overrides)) {
                continue;
            }

            $candidates = array_diff_key($candidates, $taken);
            arsort($candidates);

            if ($candidates === []) {
                continue;
            }

            $best = array_key_first($candidates);
            $mapping[$field] = $best;
            $confidence[$field] = round($candidates[$best], 2);
            $taken[$best] = true;

            $close = array_keys(array_filter(
                $candidates,
                fn ($score, $header) => $header !== $best && $score >= self::CONFIDENT && $candidates[$best] - $score < self::AMBIGUITY_GAP,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($close !== [] && in_array($field, ['phone', 'email', 'full_name'], true)) {
                $ambiguous[] = ['field' => $field, 'candidates' => array_values([$best, ...$close])];
            }
        }

        // A person's choice stands, whatever the guess would have been.
        foreach ($overrides as $field => $header) {
            if (! array_key_exists($field, self::ALIASES)) {
                continue;
            }

            if ($header !== null && in_array($header, $headers, true)) {
                unset($mapping[array_search($header, $mapping, true) ?: '__none']);
                $mapping[$field] = $header;
                $confidence[$field] = 1.0;
                $taken[$header] = true;
            } else {
                unset($mapping[$field], $confidence[$field]);
            }
        }

        $ambiguous = array_values(array_filter($ambiguous, fn ($a) => ! array_key_exists($a['field'], $overrides)));

        // Whatever is left is an answer to a question nobody told us about.
        $custom = [];

        foreach ($headers as $header) {
            if (! in_array($header, $mapping, true)) {
                $custom[$header] = self::customKey($header);
            }
        }

        return [
            'mapping' => $mapping,
            'confidence' => $confidence,
            'ambiguous' => $ambiguous,
            'custom' => $custom,
            'usable' => isset($mapping['phone']) || isset($mapping['email']),
        ];
    }

    /** The fields this mapper knows, for a screen that offers a choice. */
    public static function fields(): array
    {
        return array_keys(self::ALIASES);
    }

    /**
     * A header folded to the form aliases are written in.
     *
     * Arabic is folded the way people actually vary it: hamza forms of alef to
     * a bare alef, taa marbuta to haa, alef maqsura to yaa, diacritics and the
     * tatweel removed.
     */
    public static function normalise(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value) ?? $value;
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /** A stable, ASCII-safe key for a column that becomes a custom field. */
    public static function customKey(string $header): string
    {
        $slug = Str::slug(self::normalise($header), '_');

        // An all-Arabic header transliterates to nothing on some systems; a
        // hash keeps the key stable rather than colliding every such column.
        return 'sheet_'.($slug !== '' ? Str::limit($slug, 40, '') : substr(md5(self::normalise($header)), 0, 10));
    }

    private function score(string $normal, array $aliases): float
    {
        foreach ($aliases as $position => $alias) {
            if ($normal === self::normalise($alias)) {
                // The platform's own name is certain; a looser synonym less so.
                return $position < 3 ? 1.0 : 0.95;
            }
        }

        foreach ($aliases as $alias) {
            $alias = self::normalise($alias);

            if (in_array($alias, self::EXACT_ONLY, true) || mb_strlen($alias) < 4) {
                continue;
            }

            // `whatsapp_phone_number`, `ما_هو_رقم_الجوال`: the alias as a whole word.
            if (preg_match('/(^|_)'.preg_quote($alias, '/').'(_|$)/u', $normal)) {
                return 0.8;
            }
        }

        return 0;
    }

    /** What a column's values look like, when most of them look like one thing. */
    private function sniff(array $values): ?string
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== ''));

        if (count($values) < 2) {
            return null;
        }

        $emails = count(array_filter($values, fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false));
        $phones = count(array_filter($values, fn ($v) => preg_match('/^(p:)?\+?[\d\s\-()]{8,18}$/', $v) === 1 && preg_match_all('/\d/', $v) >= 8));

        return match (true) {
            $emails / count($values) >= 0.6 => 'email',
            $phones / count($values) >= 0.6 => 'phone',
            default => null,
        };
    }
}
