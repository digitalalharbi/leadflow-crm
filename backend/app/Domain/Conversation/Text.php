<?php

namespace App\Domain\Conversation;

/** Arabic and English text made comparable: one alef, one ya, no diacritics, Latin digits. */
final class Text
{
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text) ?? $text; // tashkeel, tatweel
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
