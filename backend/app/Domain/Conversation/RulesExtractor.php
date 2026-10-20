<?php

namespace App\Domain\Conversation;

/**
 * The fallback — and the floor — for reading a conversation: plain, listed,
 * explainable rules over what the customer wrote, in Arabic and English.
 *
 * It is not clever and does not pretend to be. It finds what a person would
 * point at in a sentence ("غالي", "ابي زيارة", "فيلا", "مليون ونصف") and
 * nothing it cannot point at. Used when no Claude key is configured and when
 * Claude fails, and the screen says so.
 */
final class RulesExtractor
{
    /** Phrases, normalised on first use. Order inside a kind is priority: the first kind-value that matches wins for single-valued kinds. */
    private const INTENT = [
        'not_interested' => ['غير مهتم', 'مو مهتم', 'ما ابي', 'ما ابغى', 'ما ابغي', 'لا شكرا', 'شيلوني', 'لا تتواصل', 'unsubscribe', 'not interested', 'remove me', 'stop messaging', 'leave me alone'],
        'wrong_contact' => ['رقم غلط', 'رقم خطا', 'مو انا', 'wrong number', 'wrong person'],
        'ready_to_buy' => ['ابي احجز', 'ابغى احجز', 'ابغي احجز', 'جاهز للشراء', 'ابي اشتري', 'ابغى اشتري', 'ابغي اشتري', 'نكمل الاجراءات', 'اريد الشراء', 'ارغب بالشراء', 'i want to buy', 'ready to buy', "let's proceed", 'book it', 'i will take it', 'reserve'],
        'callback_request' => ['كلمني', 'اتصل علي', 'اتصلوا', 'ارجع لي', 'تواصل معي', 'call me', 'call back', 'give me a call', 'phone me'],
        'comparing' => ['اقارن', 'عند غيركم', 'شركه ثانيه', 'عرض ثاني', 'مطور ثاني', 'comparing', 'other offer', 'another company', 'other developer', 'competitor'],
        'interested' => ['مهتم', 'يهمني', 'يعجبني', 'ابي اعرف', 'اكيد', 'نعم', 'interested', 'sounds good', 'yes please', 'i like it'],
        'inquiring' => ['كم السعر', 'وش الاسعار', 'اسعار', 'تفاصيل', 'معلومات', 'بروشور', 'ارسل', 'وين', 'متى', 'how much', 'price', 'details', 'brochure', 'where is', 'when'],
    ];

    private const OBJECTION = [
        'price_high' => ['غالي', 'السعر مرتفع', 'اغلى', 'فوق ميزانيتي', 'ما يناسبني السعر', 'السعر عالي', 'expensive', 'too high', 'over my budget', 'pricey', 'too much'],
        'location' => ['بعيد', 'الموقع ما يناسب', 'موقع ما يعجبني', 'مو قريب', 'too far', 'far from', 'location is not'],
        'financing' => ['ما عندي تمويل', 'رفضوا التمويل', 'مشكله التمويل', 'التمويل مشكله', 'ما وافق البنك', 'cannot get financing', 'no financing', 'loan was rejected', 'mortgage problem'],
        'timing' => ['مو الحين', 'مو وقته', 'بعدين', 'لاحقا', 'بعد فتره', 'not now', 'maybe later', 'next year', 'not the right time'],
        'trust' => ['ما اثق', 'مو واثق', 'مصداقيه', 'احتيال', 'ضمان', 'do not trust', "don't trust", 'scam', 'guarantee', 'credibility'],
        'competitor' => ['عند غيركم', 'شركه ثانيه', 'عرض ثاني', 'مطور ثاني', 'other developer', 'another company', 'better offer'],
        'family_decision' => ['اشاور', 'استشير', 'اهلي', 'زوجتي', 'زوجي', 'ابوي', 'اسالهم', 'my wife', 'my husband', 'my family', 'consult', 'discuss with'],
    ];

    private const URGENCY = [
        'high' => ['الحين', 'اليوم', 'بكرا', 'بكره', 'عاجل', 'بسرعه', 'هالاسبوع', 'هذا الاسبوع', 'asap', 'urgent', 'today', 'tomorrow', 'this week', 'right now', 'immediately'],
        'medium' => ['هالشهر', 'هذا الشهر', 'خلال شهر', 'شهرين', 'اسبوعين', 'this month', 'few weeks', 'two months', 'within a month'],
        'low' => ['مو مستعجل', 'بدون استعجال', 'السنه الجايه', 'بعد سنه', 'مستقبلا', 'no rush', 'not in a hurry', 'next year', 'someday'],
    ];

    /** @var array<string,list<string>> value → phrases, for the single-valued service fact */
    private const SERVICE = [
        'townhouse' => ['تاون هاوس', 'تاونهاوس', 'townhouse', 'town house'],
        'villa' => ['فيلا', 'فلل', 'villa'],
        'apartment' => ['شقه', 'شقق', 'apartment', 'flat'],
        'land' => ['ارض', 'اراضي', 'land', 'plot'],
        'commercial' => ['تجاري', 'محل', 'مكتب', 'commercial', 'shop', 'office space'],
    ];

    private const APPOINTMENT = [
        'requested' => ['ابي زياره', 'ابغى زياره', 'ابغي زياره', 'ابي ازور', 'ابغى ازور', 'ابغي ازور', 'ازور الموقع', 'ابي اشوف', 'ابغى اشوف', 'ابي موعد', 'احجز موعد', 'معاينه', 'ابي اشوفه', 'visit', 'site visit', 'viewing', 'book an appointment', 'schedule a visit', 'see the property'],
        'interested' => ['ممكن ازور', 'ممكن نتفق', 'ممكن اشوف', 'maybe visit', 'can i visit', 'could we meet'],
    ];

    private const BUDGET = [
        'tight' => ['ميزانيتي محدوده', 'ميزانيه محدوده', 'ما اقدر ازيد', 'tight budget', 'limited budget', 'cannot go higher'],
        'flexible' => ['ميزانيتي مرنه', 'اقدر ازيد', 'budget is flexible', 'flexible on price', 'can stretch'],
    ];

    /** Buying or renting. A customer who names both ("شراء مو ايجار") is buying: the rent word is the one being refused. */
    private const TRANSACTION = [
        'buy' => ['للبيع', 'تمليك', 'ابي اشتري', 'ابغى اشتري', 'ابغي اشتري', 'اريد الشراء', 'ارغب بالشراء', 'شراء', 'اشتري', 'to buy', 'buy ', 'purchase', 'for sale'],
        // Not a bare "ايجار": "عائد ايجاري" is a rental yield, which is an investment, not a tenancy.
        'rent' => ['للايجار', 'ابي ايجار', 'ابغى ايجار', 'ابغي ايجار', 'اريد ايجار', 'استئجار', 'ابي استاجر', 'ابغى استاجر', 'ابغي استاجر', 'for rent', 'to rent', 'want to rent', 'renting', 'lease'],
    ];

    private const PAYMENT = [
        'cash' => ['كاش', 'نقدا', 'نقدي', 'دفعه واحده', 'cash'],
        'financing' => ['ابي تمويل', 'ابغى تمويل', 'ابغي تمويل', 'بتمويل', 'تمويل عقاري', 'تمويل بنكي', 'عن طريق البنك', 'قرض', 'mortgage', 'financing', 'bank loan', 'finance it'],
    ];

    private const PURPOSE = [
        'investment' => ['استثمار', 'استثمر', 'للاستثمار', 'عائد', 'investment', 'invest', 'rental yield'],
        'residence' => ['للسكن', 'سكن', 'اسكن', 'بيت العمر', 'residence', 'to live in', 'live there', 'family home'],
    ];

    private const RESERVATION = [
        'ready' => ['ابي احجز وحده', 'ابغى احجز وحده', 'ابغي احجز وحده', 'ابي احجزها', 'ابغى احجزها', 'احجز لي', 'حجز الوحده', 'ادفع العربون', 'دفع العربون', 'ارسل رابط الدفع', 'reserve it', 'reserve a unit', 'put a deposit', 'pay the deposit', 'book the unit'],
        'interested' => ['كيف الحجز', 'كيف احجز', 'شروط الحجز', 'كم العربون', 'how to reserve', 'reservation process', 'how much is the deposit'],
    ];

    /** Spoken numbers of rooms: the ones people actually say. */
    private const ROOM_WORDS = ['غرفه واحده' => 1, 'غرفتين' => 2, 'ثلاث غرف' => 3, 'ثلاثه غرف' => 3, 'اربع غرف' => 4, 'اربعه غرف' => 4, 'خمس غرف' => 5, 'خمسه غرف' => 5, 'ست غرف' => 6, 'سته غرف' => 6, 'one bedroom' => 1, 'two bedrooms' => 2, 'three bedrooms' => 3, 'four bedrooms' => 4, 'five bedrooms' => 5];

    /** Words that end a district's name rather than belonging to it. */
    private const DISTRICT_STOP = [
        'في', 'او', 'و', 'على', 'عند', 'ب', 'مع', 'قريب', 'وين', 'فيه', 'بس', 'ابي', 'ابغى', 'ابغي', 'مناسب', 'سعر', 'اسعار',
        // What people say right after a district: how they will pay, what they want, how much.
        'كاش', 'نقدا', 'تمويل', 'فيلا', 'شقه', 'تاون', 'ارض', 'دوبلكس', 'ميزانيتي', 'ميزانيه', 'غرف', 'غرفه', 'مليون', 'الف', 'ريال', 'لو', 'اذا', 'يعني', 'شكرا',
    ];

    /** Questions, not statements: asking the price is a buying signal, saying it is high is an objection. */
    private const INQUIRY = [
        'price' => ['كم السعر', 'وش السعر', 'كم سعر', 'كم يبدا', 'كم يبدأ', 'ابي اعرف السعر', 'اسعار', 'قائمه الاسعار', 'how much', 'what is the price', 'price list', 'pricing'],
        'financing' => ['فيه تمويل', 'يوجد تمويل', 'تمويل؟', 'كيف التمويل', 'تقسيط', 'خطه الدفع', 'خطة الدفع', 'دفعه اولي', 'دفعه اولى', 'down payment', 'payment plan', 'installments', 'do you offer financing'],
        'location' => ['وين الموقع', 'اين الموقع', 'الموقع وين', 'ارسل الموقع', 'لوكيشن', 'where is it', 'send the location', 'location pin'],
        'availability' => ['متوفر', 'فيه وحدات', 'باقي وحدات', 'is it available', 'any units left', 'still available'],
    ];

    private const POSITIVE = ['ممتاز', 'حلو', 'رائع', 'شكرا', 'مناسب', 'يعجبني', 'تمام', 'great', 'good', 'thanks', 'thank you', 'perfect', 'love it', 'excellent'];

    private const NEGATIVE = ['سيء', 'مزعج', 'كفايه', 'ازعاج', 'ما عجبني', 'زعلان', 'bad', 'annoying', 'angry', 'terrible', 'not happy', 'disappointed', 'waste of time'];

    /** Narrowest first: "خلال شهرين" must not be read as "خلال شهر". */
    private const TIMELINE = [
        'within_6_months' => ['6 اشهر', 'ست اشهر', 'ستة اشهر', 'six months', '6 months'],
        'within_3_months' => ['3 اشهر', 'ثلاث اشهر', 'ثلاثه اشهر', 'شهرين', 'خلال 3', 'three months', '3 months', 'two months', 'few months'],
        'within_1_month' => ['خلال شهر', 'هالشهر', 'هذا الشهر', 'within a month', 'this month'],
        'immediate' => ['الحين', 'اليوم', 'فورا', 'asap', 'immediately', 'right now'],
        'later' => ['السنه الجايه', 'بعد سنه', 'next year', 'in a year', 'later this year'],
    ];

    /** @var array<string,array<int|string,mixed>>|null */
    private static ?array $compiled = null;

    /**
     * @param  list<string>  $customerMessages  what the customer wrote, oldest first
     * @return list<Fact>
     */
    public function extract(array $customerMessages): array
    {
        $text = Text::normalise(implode("\n", $customerMessages));

        if ($text === '') {
            return [];
        }

        $facts = [];
        $lex = self::lexicon();

        // Single-valued kinds: the first value, in priority order, that is found.
        foreach ([Vocabulary::INTENT => 'intent', Vocabulary::URGENCY => 'urgency', Vocabulary::APPOINTMENT => 'appointment', Vocabulary::SERVICE => 'service'] as $kind => $set) {
            foreach ($lex[$set] as $value => $phrases) {
                if ($this->has($text, $phrases)) {
                    $detail = $kind === Vocabulary::URGENCY ? $this->timeline($text, $lex['timeline']) : null;
                    $facts[] = new Fact($kind, $value, $kind === Vocabulary::INTENT && $value === 'inquiring' ? 55 : 75, $detail);
                    break;
                }
            }
        }

        // What they want, in real estate terms: said in a sentence, kept as a fact.
        foreach ([Vocabulary::TRANSACTION => 'transaction', Vocabulary::PAYMENT => 'payment', Vocabulary::PURPOSE => 'purpose', Vocabulary::RESERVATION => 'reservation'] as $kind => $set) {
            foreach ($lex[$set] as $value => $phrases) {
                if ($this->has($text, $phrases)) {
                    $facts[] = new Fact($kind, $value, 75);
                    break;
                }
            }
        }

        if (($rooms = $this->bedrooms($text)) !== null) {
            $facts[] = new Fact(Vocabulary::BEDROOMS, (string) $rooms, 80);
        }

        if (($district = $this->district($text)) !== null) {
            $facts[] = new Fact(Vocabulary::DISTRICT, $district, 70);
        }

        // A stated amount beats a feeling about the price.
        if ($amount = AmountParser::parse($text)) {
            $facts[] = new Fact(Vocabulary::BUDGET, 'stated', 85, $amount + ['currency' => 'SAR']);
        } else {
            foreach ($lex['budget'] as $value => $phrases) {
                if ($this->has($text, $phrases)) {
                    $facts[] = new Fact(Vocabulary::BUDGET, $value, 70);
                    break;
                }
            }
        }

        // Questions accumulate: everything they asked about.
        foreach ($lex['inquiry'] as $value => $phrases) {
            if ($this->has($text, $phrases)) {
                $facts[] = new Fact(Vocabulary::INQUIRY, $value, 70);
            }
        }

        // Objections accumulate: every one that is found.
        foreach ($lex['objection'] as $value => $phrases) {
            if ($this->has($text, $phrases)) {
                $facts[] = new Fact(Vocabulary::OBJECTION, $value, 70);
            }
        }

        $score = $this->hits($text, $lex['positive']) - $this->hits($text, $lex['negative']);
        $facts[] = new Fact(Vocabulary::SENTIMENT, $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral'), $score === 0 ? 50 : 65);

        return $facts;
    }

    /** @return array{timeline:string}|null */
    private function timeline(string $text, array $timelines): ?array
    {
        foreach ($timelines as $code => $phrases) {
            if ($this->has($text, $phrases)) {
                return ['timeline' => $code];
            }
        }

        return null;
    }

    /** "3 غرف", "ثلاث غرف", "two bedrooms" → 3, 3, 2; nothing when no number of rooms was said. */
    private function bedrooms(string $text): ?int
    {
        if (preg_match('/(\d{1,2})\s*(?:غرف|غرفه|bed(?:room)?s?|br)\b/u', $text, $m) === 1) {
            $n = (int) $m[1];

            return $n >= 1 && $n <= 20 ? $n : null;
        }

        foreach (self::lexicon()['rooms'] as $phrase => $n) {
            if (str_contains($text, $phrase)) {
                return $n;
            }
        }

        return null;
    }

    /** "في حي النرجس" → النرجس; the name only, never the sentence around it. */
    private function district(string $text): ?string
    {
        if (preg_match('/(?:^|\s)حي\s+((?:[\p{Arabic}]{2,})(?:\s+[\p{Arabic}]{2,}){0,2})/u', $text, $m) === 1) {
            $words = preg_split('/\s+/u', trim($m[1])) ?: [];
            $name = [];

            foreach ($words as $i => $word) {
                // The first word is always part of the name; later ones stop at a connector.
                if ($i > 0 && (in_array($word, self::lexicon()['stop'], true) || preg_match('/^[وبل][\p{Arabic}]{3,}$/u', $word) === 1)) {
                    break;
                }
                $name[] = $word;
            }

            $name = implode(' ', array_slice($name, 0, 2));

            return Vocabulary::valid(Vocabulary::DISTRICT, $name) ? $name : null;
        }

        if (preg_match('/\b(?:in|at)\s+(?:the\s+)?([a-z]{3,}(?:\s[a-z]{3,})?)\s+(?:district|neighbou?rhood|area)\b/u', $text, $m) === 1) {
            return Vocabulary::valid(Vocabulary::DISTRICT, $m[1]) ? $m[1] : null;
        }

        return null;
    }

    private function has(string $text, array $phrases): bool
    {
        return $this->hits($text, $phrases) > 0;
    }

    private function hits(string $text, array $phrases): int
    {
        $n = 0;

        foreach ($phrases as $phrase) {
            if ($phrase !== '' && str_contains($text, $phrase)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string,array<string,list<string>>> */
    private static function lexicon(): array
    {
        return self::$compiled ??= [
            'intent' => self::compile(self::INTENT),
            'objection' => self::compile(self::OBJECTION),
            'urgency' => self::compile(self::URGENCY),
            'service' => self::compile(self::SERVICE),
            'appointment' => self::compile(self::APPOINTMENT),
            'budget' => self::compile(self::BUDGET),
            'timeline' => self::compile(self::TIMELINE),
            'transaction' => self::compile(self::TRANSACTION),
            'payment' => self::compile(self::PAYMENT),
            'purpose' => self::compile(self::PURPOSE),
            'reservation' => self::compile(self::RESERVATION),
            'inquiry' => self::compile(self::INQUIRY),
            'rooms' => array_combine(array_map([Text::class, 'normalise'], array_keys(self::ROOM_WORDS)), array_values(self::ROOM_WORDS)),
            'stop' => array_map([Text::class, 'normalise'], self::DISTRICT_STOP),
            'positive' => array_map([Text::class, 'normalise'], self::POSITIVE),
            'negative' => array_map([Text::class, 'normalise'], self::NEGATIVE),
        ];
    }

    /**
     * @param  array<string,list<string>>  $set
     * @return array<string,list<string>>
     */
    private static function compile(array $set): array
    {
        return array_map(fn (array $phrases) => array_map([Text::class, 'normalise'], $phrases), $set);
    }
}
