<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case GenericWebhook = 'generic_webhook';
    case GoogleSheets = 'google_sheets';
    case Meta = 'meta';
    case TikTok = 'tiktok';
    case GoogleAds = 'google_ads';
    case LinkedIn = 'linkedin';
    case Snapchat = 'snapchat';
    case CsvImport = 'csv_import';
    case Whatsapp = 'whatsapp';
    case GitHub = 'github';
    case TwilioVoice = 'twilio_voice';
    case Email = 'email';
    case Manual = 'manual';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function defaultPlatform(): Platform
    {
        return match ($this) {
            self::GenericWebhook => Platform::Webhook,
            self::GoogleSheets => Platform::GoogleSheets,
            self::Meta => Platform::Meta,
            self::TikTok => Platform::TikTok,
            self::GoogleAds => Platform::GoogleAds,
            self::LinkedIn => Platform::LinkedIn,
            self::Snapchat => Platform::Snapchat,
            self::CsvImport => Platform::Import,
            // WhatsApp is a conversation channel and GitHub is a record of how
            // the product got built — neither is a lead source. A lead that
            // first appears over WhatsApp keeps whatever platform brought it in,
            // and GitHub never creates one at all.
            self::Whatsapp, self::GitHub, self::TwilioVoice, self::Email, self::Manual => Platform::Manual,
        };
    }
}
