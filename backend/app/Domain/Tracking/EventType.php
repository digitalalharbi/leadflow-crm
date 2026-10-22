<?php

namespace App\Domain\Tracking;

/**
 * Everything the trail can say, in one closed list.
 *
 * The first nine are what a visitor does and a landing page can report; the last four are what
 * the sales process does to a lead, and only the server records them — a browser must never be
 * able to claim a reservation or a sale.
 */
enum EventType: string
{
    case Visit = 'visit';
    case Click = 'click';
    case FormStart = 'form_start';
    case FormSubmit = 'form_submit';
    case WhatsappClick = 'whatsapp_click';
    case CallClick = 'call_click';
    case BrochureOpen = 'brochure_open';
    case ProjectView = 'project_view';
    case PropertyView = 'property_view';
    case AppointmentRequest = 'appointment_request';
    case SiteVisit = 'site_visit';
    case Reservation = 'reservation';
    case Won = 'won';

    /** What a landing page or a link may report about a visitor. */
    public static function browserSafe(): array
    {
        return [self::Visit, self::Click, self::FormStart, self::FormSubmit, self::WhatsappClick, self::CallClick, self::BrochureOpen, self::ProjectView, self::PropertyView];
    }

    /** @return array<int,string> */
    public static function browserSafeValues(): array
    {
        return array_map(fn (self $t) => $t->value, self::browserSafe());
    }

    /** The events that start or continue a path to the lead — the touches attribution credits. */
    public static function touches(): array
    {
        return [self::Visit, self::Click, self::WhatsappClick, self::CallClick];
    }

    /** @return array<int,string> */
    public static function touchValues(): array
    {
        return array_map(fn (self $t) => $t->value, self::touches());
    }

    /** What sales did, as opposed to what the visitor did. */
    public function isOutcome(): bool
    {
        return in_array($this, [self::AppointmentRequest, self::SiteVisit, self::Reservation, self::Won], true);
    }
}
