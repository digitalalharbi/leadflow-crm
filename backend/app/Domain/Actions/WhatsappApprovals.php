<?php

namespace App\Domain\Actions;

use App\Enums\Role;
use App\Models\ActionProposal;
use App\Models\ApprovalToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agent proposal → WhatsApp → approve or reject → execute → audit.
 *
 * Each admin and manager with a phone gets their own link; the link is a
 * one-time key, not a session, so it works from a phone without signing in and
 * cannot be reused or forwarded into a second decision.
 *
 * Business-initiated WhatsApp to staff outside a 24-hour window needs a
 * template approved by Meta. When the workspace names one
 * (`approval_template` on its WhatsApp connection) it is used; otherwise a plain
 * message is tried and, if WhatsApp refuses it, the failure is recorded on the
 * token rather than pretended away.
 */
final class WhatsappApprovals
{
    public const TTL_HOURS = 24;

    public function __construct(private WhatsappManager $whatsapp, private AuditLogger $audit) {}

    /** @return list<array{user: string, delivery: string, error: ?string}> */
    public function notify(ActionProposal $proposal): array
    {
        if ($proposal->status !== ActionProposal::PENDING) {
            return [];
        }

        $integration = $this->whatsapp->integration();

        if (! $this->whatsapp->canSend($integration)) {
            return [];
        }

        $provider = $this->whatsapp->providerFor($integration);
        $template = data_get($integration->config, 'approval_template');
        $results = [];

        $approvers = User::query()->whereIn('role', [Role::Admin->value, Role::Manager->value])
            ->where('is_active', true)->whereNotNull('phone')->get();

        foreach ($approvers as $user) {
            $token = Str::random(48);
            $row = ApprovalToken::create([
                'action_proposal_id' => $proposal->id,
                'user_id' => $user->id,
                'token_hash' => ApprovalToken::hash($token),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);

            $link = rtrim((string) config('app.url'), '/').'/approve/'.$token;
            $body = $this->message($proposal, $link);

            try {
                $sent = $template
                    ? $provider->sendTemplate($integration, (string) $user->phone, (string) $template, 'ar', [$this->summary($proposal), $link])
                    : $provider->sendText($integration, (string) $user->phone, $body);
                $error = $sent->accepted ? null : trim(($sent->failureCode ?? '').' '.($sent->failureReason ?? 'refused'));
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $row->update(['delivery' => $error === null ? 'sent' : 'failed', 'delivery_error' => $error ? mb_substr($error, 0, 300) : null]);
            $results[] = ['user' => $user->name, 'delivery' => $error === null ? 'sent' : 'failed', 'error' => $error];
        }

        $this->audit->log('action_proposal.whatsapp_sent', null, $proposal, ['recipients' => count($results)]);

        return $results;
    }

    private function summary(ActionProposal $p): string
    {
        $agent = $p->agent ? ['campaign' => 'وكيل الحملات', 'creative' => 'وكيل الإبداع'][$p->agent] ?? $p->agent : 'اقتراح';

        return sprintf('%s: %s — %s', $agent, ActionLabels::ar($p->action), $p->campaign?->name ?? '');
    }

    private function message(ActionProposal $p, string $link): string
    {
        return implode("\n", array_filter([
            '«مهتم» — إجراء ينتظر قرارك',
            $this->summary($p),
            'السبب: '.mb_substr($p->reason, 0, 300),
            $p->expected_impact ? 'الأثر المتوقع: '.mb_substr($p->expected_impact, 0, 200) : null,
            'للموافقة أو الرفض (صالح 24 ساعة، مرة واحدة):',
            $link,
        ]));
    }
}
