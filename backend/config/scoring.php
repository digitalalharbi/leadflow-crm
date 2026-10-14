<?php

/*
|--------------------------------------------------------------------------
| Lead scoring
|--------------------------------------------------------------------------
|
| A lead's score answers one question for a sales manager: who should my team
| call first? So every rule here has to be explainable in a sentence — no
| model, no opaque weighting. A rep who disagrees with a score can look at the
| reasons the API returns and see exactly which rule fired.
|
| These are the defaults. They are read through config rather than hard-coded
| so that a later release can override them per tenant without touching the
| service: a developer selling villas off-plan and one selling finished flats
| do not value "timeline within a month" the same way.
|
| Deliberately absent: anything about who the person is. Nationality, origin,
| language and name must never move a score. Only their interest, their fit
| with the product, budget, timing, financing, engagement and whether they
| want an appointment.
|
*/

return [
    'weights' => [
        'budget_matched' => 25,
        'timeline_within_3_months' => 20,
        'financing_approved' => 15,
        'wants_appointment' => 25,
        'replied_on_whatsapp' => 10,
        'budget_mismatch' => -30,
        'wrong_number' => -100,
    ],

    /*
     * Partial credit, so a half-qualified lead still sorts above an untouched
     * one. These are smaller than the headline rules on purpose: knowing what
     * someone wants is worth less than knowing they can buy it.
     */
    'secondary' => [
        'budget_known' => 10,
        'timeline_within_6_months' => 8,
        'financing_in_progress' => 7,
        'payment_cash' => 10,
        'purpose_known' => 4,
        'property_type_known' => 4,
        'location_known' => 4,
        'project_known' => 5,
        'contacted' => 5,
    ],

    /*
     * What the customer did, as opposed to what they told us.
     *
     * Everything above is a claim on a form; this is behaviour, and behaviour
     * is the better predictor — somebody who opened the brochure twice this
     * morning is further along than somebody who ticked "within three months"
     * in March.
     *
     * Capped, and deliberately low per event. A lead who opens a link eleven
     * times is interested, not eleven times more interested, and without a
     * ceiling the loudest signal in the product would be a mail client that
     * prefetches images.
     */
    'engagement' => [
        'file_opened' => 8,
        'file_opened_again' => 6,
        'email_opened' => 4,
        'link_clicked' => 6,
        'whatsapp_replied' => 10,
        'max' => 22,
    ],

    /*
     * The floor every lead that arrived through a real campaign starts from.
     * Someone who filled in a form is not a zero.
     */
    'baseline' => 15,

    'temperature' => [
        'warm_from' => 40,
        'hot_from' => 70,
    ],

    /*
     * A project's own price band is the yardstick for "budget matched". Without
     * one, a stated budget still counts as a known budget — it just cannot be
     * matched or mismatched against anything.
     */
    'budget' => [
        // A lead whose top budget is within this fraction of the asking price
        // still counts as matched; property is negotiated, not scanned.
        'tolerance' => 0.85,
    ],
];
