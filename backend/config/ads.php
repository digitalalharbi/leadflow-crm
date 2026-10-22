<?php

return [
    /*
     * Sandbox: where a platform has no credentials, an approved campaign change is carried out
     * against a stand-in that records exactly what would have been sent and answers "ok" — so the
     * whole path (propose → approve → execute → log → our copy updated) can be used and tested
     * before any account is connected.
     *
     * Never in production: a deployment that customers reach does not pretend to change their
     * campaigns. The log marks every sandbox execution, and nothing is called "live" because of one.
     */
    'sandbox' => (bool) env('ADS_SANDBOX', false),
];
