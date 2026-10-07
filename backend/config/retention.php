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
];
