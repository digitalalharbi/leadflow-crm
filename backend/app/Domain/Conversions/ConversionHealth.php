<?php

namespace App\Domain\Conversions;

use App\Domain\Integrations\IntegrationState;
use App\Models\ConversionExport;

/**
 * Whether sales are actually reaching each ad platform.
 *
 * Per platform: is it configured (and if not, which keys are missing), what
 * the last thirty days of exports did — sent, waiting, failed, unmatchable —
 * when the last one landed, and the last error, on the same ladder as every
 * integration. Only a conversion the platform accepted moves it to live.
 */
final class ConversionHealth
{
    public function __construct(private ExporterRegistry $registry) {}

    /** @return list<array<string, mixed>> */
    public function summary(): array
    {
        $since = now()->subDays(30);

        $counts = ConversionExport::query()->where('created_at', '>=', $since)
            ->selectRaw('platform, status, count(*) as n')->groupBy('platform', 'status')->get()
            ->groupBy('platform')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n));

        $lastSent = ConversionExport::query()->where('status', ConversionExport::SENT)
            ->selectRaw('platform, max(sent_at) as at')->groupBy('platform')->pluck('at', 'platform');

        $lastError = ConversionExport::query()->where('status', ConversionExport::FAILED)->latest('updated_at')
            ->get(['platform', 'last_error'])->unique('platform')->pluck('last_error', 'platform');

        return array_map(function (string $platform) use ($counts, $lastSent, $lastError) {
            $exporter = $this->registry->get($platform);
            $credentials = ConversionCredentials::for($platform);
            $configured = $exporter->isConfigured($credentials);
            $sentAt = $lastSent[$platform] ?? null;

            $state = match (true) {
                ! $configured => IntegrationState::NOT_CONFIGURED,
                $sentAt === null => IntegrationState::CODE_VERIFIED,
                app()->environment('production') && strtotime((string) $sentAt) >= now()->subDays(7)->timestamp => IntegrationState::PRODUCTION_VERIFIED,
                default => IntegrationState::LIVE_VERIFIED,
            };

            return [
                'platform' => $platform,
                'state' => $state,
                'configured' => $configured,
                'missing' => array_values(array_filter($exporter->requiredCredentials(), fn ($k) => blank($credentials[$k] ?? null))),
                'counts' => $counts->get($platform, collect())->all(),
                'last_sent_at' => $sentAt ? date(DATE_ATOM, strtotime((string) $sentAt)) : null,
                'last_error' => $lastError[$platform] ?? null,
            ];
        }, $this->registry->platforms());
    }
}
