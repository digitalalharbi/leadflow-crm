<?php

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The product has one name, and it is مهتم.
 *
 * Every name a product has ever been called survives somewhere — a mail "from"
 * line, a notification title, a seeded demo record — and each one that stays is
 * a customer asking which company they are actually buying from.
 *
 * The line this draws is between what a person reads and what a machine reads.
 * `php artisan leadflow:daily-digest`, the repository, the database, the Redis
 * prefix and the deploy path are identifiers: renaming them would break running
 * systems to satisfy a test, and nobody outside the team ever sees them. What a
 * notification says, and what the platform calls itself when nothing more
 * specific applies, are read by customers.
 */
class TheProductHasOneNameTest extends TestCase
{
    /**
     * Names the product has been called, or could be mistaken for, as whole
     * words — ordinary English has to survive this. A chart may describe the
     * flow of leads, and "CRM" on its own is what the thing is rather than what
     * it is called.
     *
     * @var array<string,string>
     */
    private const FORBIDDEN = [
        'LeadFlow' => '/lead[\s_-]*flow/i',
        'SalesFlow' => '/sales[\s_-]*flow/i',
        'Sales Hub' => '/sales[\s_-]*hub/i',
        'Sales Machine' => '/sales[\s_-]*machine/i',
        'Lead Machine' => '/lead[\s_-]*machine/i',
        'Smart CRM' => '/smart[\s_-]*crm/i',
        // The reference the GitHub integration was modelled on. A good idea to
        // borrow, not a name to inherit.
        'CampaignsHub' => '/campaigns?[\s_-]*hub/i',
    ];

    /** Only the quoted strings: a class called `LeadFlowThing` is an identifier. */
    private function quotedStrings(string $source): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/", $source, $matches);

        return array_values(array_filter(array_merge($matches[1], $matches[2])));
    }

    private function notificationFiles(): array
    {
        return File::allFiles(app_path('Notifications'));
    }

    public function test_it_reads_the_notifications_it_claims_to_check(): void
    {
        // A scanner that silently walks nothing passes forever.
        $this->assertGreaterThanOrEqual(5, count($this->notificationFiles()));
    }

    /**
     * Notifications are the product speaking to a person who did not open it —
     * an email, a push, a row in the bell menu. They carry the name further
     * than any screen does.
     */
    public function test_nothing_a_notification_says_calls_the_product_something_else(): void
    {
        $offences = [];

        foreach ($this->notificationFiles() as $file) {
            foreach ($this->quotedStrings($file->getContents()) as $string) {
                foreach (self::FORBIDDEN as $label => $pattern) {
                    if (preg_match($pattern, $string)) {
                        $offences[] = "{$label} in {$file->getFilename()}: \"{$string}\"";
                    }
                }
            }
        }

        $this->assertSame([], $offences);
    }

    /**
     * What the platform calls itself when nothing more specific applies: the
     * mail "from" name, the Slack log username. A deployment that forgets
     * APP_NAME should not introduce itself as the framework it was built with.
     */
    public function test_the_platform_has_a_name_of_its_own_without_being_told(): void
    {
        $this->assertSame('مهتم', config('app.name'));

        // And with the variable missing entirely, which is how a half-configured
        // server sends its first email.
        $withoutEnv = require config_path('app.php');

        $this->assertNotSame('Laravel', $withoutEnv['name']);
    }

    /**
     * The identifiers that must NOT change, asserted so that a future tidy-up
     * of "the old name" cannot quietly take a running system with it.
     *
     * Renaming any of these breaks a deployed queue prefix, a scheduled command
     * or a deploy path, and buys nothing: no customer has ever seen one.
     */
    public function test_the_internal_identifiers_are_left_alone(): void
    {
        $commands = array_keys(app(Kernel::class)->all());

        $this->assertContains('leadflow:daily-digest', $commands);
        $this->assertContains('leadflow:create-super-admin', $commands);
    }
}
