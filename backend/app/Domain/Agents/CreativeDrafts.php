<?php

namespace App\Domain\Agents;

use App\Models\Ad;
use App\Models\ClientRule;
use App\Models\CreativeDraft;
use App\Models\User;
use InvalidArgumentException;

/**
 * Saves drafts, and checks them against the client's forbidden words on the way
 * in — a draft that says "مضمون" is kept, but flagged, so nobody copies it into
 * an ad without seeing why it would be refused.
 */
final class CreativeDrafts
{
    /** @return array{result: mixed, blocks: list<array<string, mixed>>} */
    public function draft(array $in, ?User $user, string $source = 'agent'): array
    {
        $ad = Ad::query()->where('uuid', (string) ($in['ad_id'] ?? ''))->first()
            ?? throw new InvalidArgumentException('No such ad in this workspace.');

        $kind = in_array($in['kind'] ?? null, ['copy', 'hook', 'variation'], true) ? $in['kind'] : throw new InvalidArgumentException('kind must be copy, hook or variation.');
        $items = array_values(array_filter(array_map(fn ($i) => mb_substr(trim((string) $i), 0, 600), (array) ($in['items'] ?? []))));

        if ($items === []) {
            throw new InvalidArgumentException('A draft needs at least one line.');
        }

        $forbidden = array_filter(array_map('mb_strtolower', ClientRule::for($ad->campaign?->project_id)->forbidden_words ?? []));
        $flags = array_values(array_filter($forbidden, fn ($w) => str_contains(mb_strtolower(implode(' ', $items)), $w)));

        $draft = CreativeDraft::create([
            'ad_id' => $ad->id,
            'kind' => $kind,
            'items' => array_slice($items, 0, 6),
            'rationale' => isset($in['rationale']) ? mb_substr((string) $in['rationale'], 0, 2000) : null,
            'source' => $source,
            'agent' => $source === 'agent' ? 'creative' : null,
            'created_by' => $user?->id,
            'flags' => $flags ?: null,
        ]);

        $view = self::present($draft);

        return ['result' => ['draft_id' => $draft->uuid, 'flags' => $flags], 'blocks' => [['type' => 'drafts', 'items' => [$view]]]];
    }

    public static function present(CreativeDraft $d): array
    {
        return [
            'id' => $d->uuid,
            'ad' => $d->ad ? ['id' => $d->ad->uuid, 'name' => $d->ad->name] : null,
            'kind' => $d->kind,
            'items' => $d->items,
            'rationale' => $d->rationale,
            'source' => $d->source,
            'status' => $d->status,
            'flags' => $d->flags ?? [],
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
