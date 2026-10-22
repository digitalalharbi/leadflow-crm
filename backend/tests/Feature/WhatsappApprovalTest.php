<?php

namespace Tests\Feature;

use App\Domain\Actions\ActionGateway;
use App\Enums\Role;
use App\Models\ActionProposal;
use App\Models\AdAccount;
use App\Models\AdConnection;
use App\Models\ApprovalToken;
use App\Models\Campaign;
use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Agent proposal → WhatsApp → approve/reject from the link → execute → audit.
 */
class WhatsappApprovalTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Approve Co');
        $this->t['admin']->forceFill(['phone' => '+966551234567'])->save();

        $this->campaign = $this->asTenant($this->t['tenant'], function () {
            Integration::create([
                'provider' => 'whatsapp', 'name' => 'WhatsApp', 'status' => Integration::CONNECTED,
                'config' => ['phone_number_id' => '1555000999'], 'credentials' => ['verify_token' => 'v', 'app_secret' => 's'], 'is_active' => true,
            ]);
            AdConnection::create(['platform' => 'meta', 'credentials' => ['access_token' => 'tok']]);
            $account = AdAccount::create(['platform' => 'meta', 'external_id' => '999']);

            return Campaign::create(['platform' => 'meta', 'external_id' => 'c1', 'name' => 'حملة', 'origin' => 'sync', 'status' => 'active', 'ad_account_id' => $account->id]);
        });

        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
    }

    private function agentProposes(): ActionProposal
    {
        return $this->asTenant($this->t['tenant'], fn () => app(ActionGateway::class)->propose([
            'action' => 'pause', 'platform' => 'meta', 'campaign_id' => $this->campaign->id, 'reason' => 'صرف بلا مؤهلين',
        ], null, 'campaign'));
    }

    /** The token is only ever in the message, so the test reads it from there. */
    private function linkToken(): string
    {
        $row = $this->asTenant($this->t['tenant'], fn () => ApprovalToken::first());
        $this->assertNotNull($row, 'No approval link was issued.');

        // Re-issue a known token for the same row: the original exists only in WhatsApp.
        $token = str_repeat('a', 48);
        $row->forceFill(['token_hash' => ApprovalToken::hash($token)])->save();

        return $token;
    }

    public function test_an_agent_proposal_reaches_the_approvers_whatsapp_with_a_one_time_link(): void
    {
        $this->agentProposes();

        $token = $this->asTenant($this->t['tenant'], fn () => ApprovalToken::first());
        $this->assertSame('sent', $token->delivery);
        $this->assertSame($this->t['admin']->id, $token->user_id);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertSame(64, strlen($token->token_hash), 'The token itself was stored.');
    }

    public function test_opening_the_link_decides_nothing_and_pressing_approve_executes_once(): void
    {
        $proposal = $this->agentProposes();
        $token = $this->linkToken();

        $this->get("/approve/{$token}")->assertOk()->assertSee('موافقة')->assertSee('صرف بلا مؤهلين');
        $this->assertSame('pending', $this->asTenant($this->t['tenant'], fn () => $proposal->fresh()->status));
        Http::assertNothingSent();

        $this->post("/approve/{$token}", ['decision' => 'approve'])->assertOk()->assertSee('نُفِّذ');

        $this->asTenant($this->t['tenant'], function () use ($proposal) {
            $p = $proposal->fresh();
            $this->assertSame('executed', $p->status);
            $this->assertSame($this->t['admin']->id, $p->decided_by);
            $this->assertSame('paused', $this->campaign->fresh()->status);
        });

        // The same link again: spent.
        $this->post("/approve/{$token}", ['decision' => 'approve'])->assertStatus(410)->assertSee('استُخدم');
        Http::assertSentCount(1);
    }

    public function test_reject_from_the_link_changes_nothing_on_the_platform(): void
    {
        $proposal = $this->agentProposes();
        $token = $this->linkToken();

        $this->post("/approve/{$token}", ['decision' => 'reject'])->assertOk();

        $this->assertSame('rejected', $this->asTenant($this->t['tenant'], fn () => $proposal->fresh()->status));
        Http::assertNothingSent();
    }

    public function test_an_expired_or_unknown_link_cannot_decide(): void
    {
        $this->agentProposes();
        $token = $this->linkToken();
        $this->asTenant($this->t['tenant'], fn () => ApprovalToken::query()->update(['expires_at' => now()->subMinute()]));

        $this->post("/approve/{$token}", ['decision' => 'approve'])->assertStatus(410);
        $this->get('/approve/'.str_repeat('b', 48))->assertOk()->assertSee('غير صالح');
        Http::assertNothingSent();
    }

    public function test_a_person_proposing_is_not_sent_to_whatsapp_and_reps_never_get_links(): void
    {
        $rep = $this->makeUser($this->t['tenant'], Role::Sales, ['phone' => '+966559999999']);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/action-proposals', ['action' => 'pause', 'campaign_id' => $this->campaign->uuid, 'reason' => 'يدوي'])->assertCreated();
        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => ApprovalToken::count()));

        $this->agentProposes();
        $this->assertSame(0, $this->asTenant($this->t['tenant'], fn () => ApprovalToken::query()->where('user_id', $rep->id)->count()));
    }

    public function test_a_refused_whatsapp_send_is_recorded_not_pretended(): void
    {
        // The mock provider refuses numbers containing 000000, as WhatsApp would refuse a non-user.
        $this->t['admin']->forceFill(['phone' => '+966500000001'])->save();
        $this->agentProposes();

        $token = $this->asTenant($this->t['tenant'], fn () => ApprovalToken::first());
        $this->assertSame('failed', $token->delivery);
        $this->assertStringContainsString('131026', $token->delivery_error);
    }
}
