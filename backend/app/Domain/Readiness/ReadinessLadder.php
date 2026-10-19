<?php

namespace App\Domain\Readiness;

use App\Domain\Integrations\IntegrationState;
use App\Support\Deployment;

/**
 * Every readiness row on the six-rung ladder, with the evidence it stands on.
 *
 *   not_configured → code_verified → sandbox_verified → ready →
 *   live_verified → production_verified
 *
 * The rule that holds the whole screen honest: nothing is *live* unless a real
 * event was recorded by a **production deployment** — an HTTPS address on a
 * public name, running as production. A mock, a local run, a test, a sandbox
 * and credentials merely entered are each evidence of something, and the
 * evidence list says of what; none of them is evidence of live.
 *
 * Evidence is a list of yes/no facts with a time where there is one. A row with
 * three red facts and a green badge cannot exist, because the badge is computed
 * from the same facts.
 */
class ReadinessLadder
{
    /**
     * @param  array<string,mixed>|null  $health  the integration's health row
     * @param  array<string,mixed>|null  $commissioning  the last commissioning run
     * @return array{state:string, evidence:list<array<string,mixed>>}
     */
    public function integration(?array $health, ?array $commissioning): array
    {
        $state = (string) ($health['state'] ?? IntegrationState::NOT_CONFIGURED);
        $real = ($health['verification'] ?? null) === 'externally_verified';

        return [
            'state' => $state,
            'evidence' => array_values(array_filter([
                $this->fact('credentials_entered', $state !== IntegrationState::NOT_CONFIGURED),
                $commissioning === null
                    ? $this->fact('accepted_by_provider', false, null, 'never_run')
                    : $this->fact('accepted_by_provider', (bool) $commissioning['ok'], $commissioning['at'] ?? null),
                $this->fact('real_event', $real, $real ? ($health['last_event_at'] ?? null) : null, $real ? null : 'none_yet'),
                $this->fact('recorded_in_production', Deployment::isProduction(), null, Deployment::kind()),
                $this->fact('no_current_error', blank($health['last_error'] ?? null), null, $health['last_error'] ?? null),
            ])),
        ];
    }

    /**
     * A row that stands for several (the ad platforms): the best of them, with
     * the evidence of the one that earned it.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    public function best(array $rows, ?array $commissioning): array
    {
        $best = null;

        foreach ($rows as $row) {
            if ($best === null || $this->rank($row['state'] ?? '') > $this->rank($best['state'] ?? '')) {
                $best = $row;
            }
        }

        return $this->integration($best, $commissioning);
    }

    /** @return array{state:string, evidence:list<array<string,mixed>>} */
    public function claude(bool $key, ?string $answeredAt): array
    {
        $production = Deployment::isProduction();

        $state = match (true) {
            ! $key => IntegrationState::NOT_CONFIGURED,
            $answeredAt === null => IntegrationState::CODE_VERIFIED,
            ! $production => IntegrationState::READY,
            strtotime($answeredAt) >= now()->subDays(7)->timestamp => IntegrationState::PRODUCTION_VERIFIED,
            default => IntegrationState::LIVE_VERIFIED,
        };

        return [
            'state' => $state,
            'evidence' => [
                $this->fact('credentials_entered', $key),
                $this->fact('real_event', $answeredAt !== null, $answeredAt, $answeredAt === null ? 'none_yet' : null),
                $this->fact('recorded_in_production', $production, null, Deployment::kind()),
            ],
        ];
    }

    /**
     * A server-side check that has no provider on the other end: the host, the
     * domain. Its checks are the evidence; production is where it counts.
     *
     * @param  list<array{key:string, ok:bool}>  $checks
     */
    public function server(string $status, array $checks): array
    {
        $production = Deployment::isProduction();

        $state = match (true) {
            $status === ProductionReadiness::LIVE && $production => IntegrationState::PRODUCTION_VERIFIED,
            $status === ProductionReadiness::LIVE, $status === ProductionReadiness::READY => IntegrationState::READY,
            default => IntegrationState::NOT_CONFIGURED,
        };

        return [
            'state' => $state,
            'evidence' => array_map(fn (array $check) => $this->fact($check['key'], (bool) $check['ok']), $checks),
        ];
    }

    /**
     * One piece of infrastructure, as the infrastructure probe reports it. A
     * probe that passes on a developer machine is "ready" — it works here —
     * and becomes production-verified only when the same probe passes on the
     * production deployment.
     *
     * @param  array<string,mixed>  $row
     * @return array{state:string, evidence:list<array<string,mixed>>}
     */
    public function infrastructure(array $row): array
    {
        $production = Deployment::isProduction();

        $state = match ($row['state'] ?? null) {
            InfrastructureReadiness::LIVE => $production ? IntegrationState::PRODUCTION_VERIFIED : IntegrationState::READY,
            InfrastructureReadiness::READY => IntegrationState::READY,
            default => IntegrationState::NOT_CONFIGURED,
        };

        return [
            'state' => $state,
            'evidence' => [
                $this->fact('probe_passed', ($row['state'] ?? null) === InfrastructureReadiness::LIVE, $row['last_check'] ?? null),
                $this->fact('no_current_error', blank($row['last_error'] ?? null), null, $row['last_error'] ?? null),
                $this->fact('recorded_in_production', $production, null, Deployment::kind()),
            ],
        ];
    }

    /** @return array{key:string, ok:bool, at:?string, detail:?string} */
    private function fact(string $key, bool $ok, ?string $at = null, ?string $detail = null): array
    {
        return ['key' => $key, 'ok' => $ok, 'at' => $at, 'detail' => $detail];
    }

    private function rank(string $state): int
    {
        $index = array_search($state, IntegrationState::LADDER, true);

        return $index === false ? -1 : $index;
    }
}
