<?php

namespace App\Domain\Tracking;

/**
 * Everything the trail can say, in one closed list.
 *
 * The first nine (and a form's steps) are what a visitor does and a landing page can report; the rest
 * are what the sales process concluded about a lead, and only the server records them — a browser must
 * never be able to claim a qualified lead, a reservation or a sale.
 */
enum EventType: string
{
    case Visit = 'visit';
    case CtaClick = 'cta_click';
    case FormView = 'form_view';
    case FormError = 'form_error';
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
    // The steps of a multi-step form, and what the system concluded about a lead: both new, both on the same trail.
    case FormStep = 'form_step';
    // A visitor who started a form and left: where they stopped (a step, a field's key — never what they typed).
    case FormAbandon = 'form_abandon';
    case LeadCreated = 'lead_created';
    case Qualified = 'qualified';
    case Interested = 'interested';
    case AppointmentBooked = 'appointment_booked';
    case Lost = 'lost';
    case Revenue = 'revenue';

    /** What a landing page or a link may report about a visitor. */
    public static function browserSafe(): array
    {
        return [self::Visit, self::Click, self::CtaClick, self::FormView, self::FormError, self::FormStart, self::FormStep, self::FormAbandon, self::FormSubmit, self::WhatsappClick, self::CallClick, self::BrochureOpen, self::ProjectView, self::PropertyView];
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
        return in_array($this, [self::AppointmentRequest, self::SiteVisit, self::Reservation, self::Won, self::LeadCreated, self::Qualified, self::Interested, self::AppointmentBooked, self::Lost, self::Revenue], true);
    }

    /**
     * The name a marketer knows it by, the same on a screen, in a dataLayer and in a platform's own
     * vocabulary: one list, so "form_submitted" means one thing everywhere.
     */
    public function canonical(): string
    {
        return match ($this) {
            self::Click => 'link_clicked',
            self::Visit => 'landing_viewed',
            self::FormStart => 'form_started',
            self::FormStep => 'form_step_completed',
            self::FormAbandon => 'form_abandoned',
            self::FormSubmit => 'form_submitted',
            self::WhatsappClick => 'whatsapp_started',
            self::CallClick => 'call_started',
            self::AppointmentRequest => 'appointment_requested',
            self::SiteVisit => 'visit_completed',
            self::Reservation => 'reservation_created',
            self::Revenue => 'revenue_recorded',
            self::BrochureOpen => 'brochure_opened',
            self::ProjectView => 'project_viewed',
            self::PropertyView => 'property_viewed',
            default => $this->value,   // lead_created · qualified · interested · appointment_booked · won · lost
        };
    }
}
