<?php

namespace App\Enums;

enum ActivityType: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case StatusChanged = 'status_changed';
    case TemperatureChanged = 'temperature_changed';
    case NoteAdded = 'note_added';
    case Called = 'called';
    case WhatsappClicked = 'whatsapp_clicked';
    case EmailSent = 'email_sent';
    case NoAnswer = 'no_answer';
    case Contacted = 'contacted';
    case FollowUpCreated = 'follow_up_created';
    case FollowUpCompleted = 'follow_up_completed';
    case AppointmentCreated = 'appointment_created';
    case Won = 'won';
    case Lost = 'lost';
    case ReEngaged = 're_engaged';
    case WhatsappSent = 'whatsapp_sent';
    case WhatsappReceived = 'whatsapp_received';
    case QualificationUpdated = 'qualification_updated';
    case ScoreChanged = 'score_changed';
    case FollowUpOutcomeRecorded = 'follow_up_outcome';
    case WhatsappOptedOut = 'whatsapp_opted_out';
    case EmailOptedOut = 'email_opted_out';
    case FileShared = 'file_shared';
    case Updated = 'updated';
    /** Automation handed the lead to a person, and the note says why. */
    case HandedOff = 'handed_off';
    case Merged = 'merged';
    case EmailReceived = 'email_received';
    case EmailBounced = 'email_bounced';
    /** A person said whether the lead is interested, over what the numbers say. */
    case InterestChanged = 'interest_changed';
    /** A rep put a unit on hold for the lead. */
    case ReservationCreated = 'reservation_created';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
