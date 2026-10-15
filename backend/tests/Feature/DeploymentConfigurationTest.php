<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The deployment files, held to the properties that cost a day each to find.
 *
 * Nothing here exercises the application. These read the compose files and the
 * example env files the way an operator's mistakes would arrive — and every one
 * is a failure that looks, from the outside, exactly like "that integration has
 * not been configured yet".
 */
class DeploymentConfigurationTest extends TestCase
{
    private function compose(string $environment): array
    {
        return Yaml::parseFile(base_path("../docker-compose.{$environment}.yml"));
    }

    /** @return array<int,string> */
    private function applicationServices(array $compose): array
    {
        return array_keys(array_filter(
            $compose['services'],
            fn (array $service) => ($service['environment'] ?? null) === $compose['x-backend-env'],
        ));
    }

    /**
     * `--env-file` is compose's own interpolation and nothing more.
     *
     * A container sees what is listed under `environment:` and, unless it is
     * given one, nothing from the operator's file. Production ran for its whole
     * life with 179 of 203 configuration variables unreachable — the Google,
     * Meta, TikTok and WhatsApp settings, mail, Reverb, the super-admin password
     * — and each read as "not configured", which is true and is not the cause.
     */
    public function test_every_application_container_receives_the_operators_env_file(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $compose = $this->compose($environment);
            $services = $this->applicationServices($compose);

            $this->assertContains('backend', $services, "{$environment}: found no application services at all");
            $this->assertContains('horizon', $services);
            $this->assertContains('scheduler', $services);

            foreach ($services as $name) {
                $this->assertContains(
                    ".env.{$environment}",
                    (array) ($compose['services'][$name]['env_file'] ?? []),
                    "{$environment}/{$name} never reads .env.{$environment}",
                );
            }
        }
    }

    public function test_no_container_can_fill_the_disk_with_its_own_log(): void
    {
        foreach (['production', 'staging'] as $environment) {
            foreach ($this->compose($environment)['services'] as $name => $service) {
                $this->assertSame('20m', $service['logging']['options']['max-size'] ?? null, "{$environment}/{$name} has no log size limit");
                $this->assertNotEmpty($service['logging']['options']['max-file'] ?? null);
            }
        }
    }

    public function test_the_alert_channel_is_reachable_without_a_code_change(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $env = $this->compose($environment)['x-backend-env'];

            // `stack`, so LOG_STACK can add a channel. `stderr` alone is a dead end.
            $this->assertSame('stack', $env['LOG_CHANNEL'], "{$environment}: alerts cannot be switched on");
            $this->assertArrayHasKey('LOG_STACK', $env);
        }

        // And it does not inherit the stream's level.
        $this->assertSame('error', config('logging.channels.slack.level'));
    }

    /**
     * The values an operator is told to set exist to be set.
     *
     * A variable the configuration reads and the example never mentions is one
     * nobody knows is there. APP_URL and FRONTEND_URL are excluded because
     * compose composes them from the domains.
     */
    public function test_every_integration_credential_is_in_the_example_env_files(): void
    {
        preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", file_get_contents(config_path('integrations.php')), $matches);

        $needed = array_diff(
            array_unique($matches[1]),
            ['APP_URL', 'FRONTEND_URL'],
            // Tunables with a sensible default; listing them blank would
            // override it with an empty string.
            ['WEBHOOK_MAX_BODY_BYTES', 'WEBHOOK_RATE_LIMIT_PER_MINUTE', 'WHATSAPP_MESSAGES_PER_LEAD',
                'WHATSAPP_RATE_LIMIT_PER_IP', 'WHATSAPP_RATE_LIMIT_PER_NUMBER'],
        );

        foreach (['production', 'staging'] as $environment) {
            $example = file_get_contents(base_path("../.env.{$environment}.example"));

            foreach ($needed as $variable) {
                $this->assertMatchesRegularExpression(
                    '/^\s*#?\s*'.preg_quote($variable, '/').'=/m',
                    $example,
                    "{$variable} is read by the configuration and absent from .env.{$environment}.example",
                );
            }
        }
    }

    /** A blank line in an env file is an empty string, which is not "use the default". */
    public function test_no_blank_example_variable_overrides_a_default(): void
    {
        $defaults = [];

        foreach (glob(config_path('*.php')) as $file) {
            preg_match_all("/env\(\s*'([A-Z0-9_]+)'\s*,\s*([^)]+?)\)/", file_get_contents($file), $found, PREG_SET_ORDER);

            foreach ($found as [, $key, $default]) {
                if (! in_array(trim($default), ['null', "''", '""', 'false'], true)) {
                    $defaults[$key] = trim($default);
                }
            }
        }

        foreach (['production', 'staging'] as $environment) {
            preg_match_all('/^([A-Z0-9_]+)=\s*$/m', file_get_contents(base_path("../.env.{$environment}.example")), $blank);

            $this->assertSame(
                [],
                array_values(array_intersect($blank[1], array_keys($defaults))),
                "{$environment}: blank lines that would override a configured default",
            );
        }
    }
}
