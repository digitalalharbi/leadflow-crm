<?php

return [
    /*
     * How long raw inbound payloads are kept, in days.
     *
     * These tables hold what a platform actually sent: names, phone numbers,
     * the text of a customer's WhatsApp message. They exist to answer "why did
     * this lead arrive wrong", which is a question asked within days of it
     * arriving — and nothing pruned them, so a workspace a year old was holding
     * a year of customers' messages for a debugging question nobody will ask.
     *
     * Keeping personal data longer than its purpose needs is the part of this
     * that is not merely untidy. The lead itself is untouched: what a customer
     * told the business is the business's record, and this is only the envelope
     * it came in.
     *
     * Thirty days by default. Long enough to investigate an integration that
     * started misbehaving last week, short enough that the envelope is not a
     * second copy of the database.
     */
    'payload_days' => (int) env('RETENTION_PAYLOAD_DAYS', 30),

    /*
     * The tracking hub's delivery log — what was sent to which platform, kept long enough to answer "why did it not get this?"
     * and to audit a month of ad-platform conversions. Ninety days.
     */
    'delivery_days' => (int) env('RETENTION_DELIVERY_DAYS', 90),

    /*
     * Visitor sessions lose what could point at a person — the address hash, the browser, the platform click ids — after this
     * long. Which source the visitor came from stays, because a lead's credit rests on it. A year.
     */
    'session_days' => (int) env('RETENTION_SESSION_DAYS', 365),
];
