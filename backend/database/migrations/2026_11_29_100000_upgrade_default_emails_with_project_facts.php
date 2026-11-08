<?php

use App\Domain\Automation\EmailDefaultsUpgrade;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new EmailDefaultsUpgrade)->run();
    }

    public function down(): void
    {
        // The earlier wording stays recoverable from MessageTemplate::EMAIL_DEFAULTS_BEFORE; nothing is undone here.
    }
};
