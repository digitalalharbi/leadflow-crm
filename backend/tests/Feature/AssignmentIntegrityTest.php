<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who a lead belongs to is the most contested field in the system — commission
 * follows it. Only someone who may reassign leads may choose an owner, and an
 * owner must be someone who can actually work the lead.
 */
class AssignmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private array $t;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Assignment Co');
        $this->rep = $this->makeUser($this->t['tenant'], Role::Sales);
    }

    public function test_a_rep_cannot_create_a_lead_owned_by_a_colleague(): void
    {
        $rep = $this->rep;
        $colleague = $this->makeSales('zameel@example.test');

        $response = $this->actingAs($rep)->postJson('/api/v1/leads', [
            'full_name' => 'عميل مسروق',
            'phone' => '0551110001',
            'assigned_to' => $colleague->uuid,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('leads', ['assigned_to' => $colleague->id]);
    }

    public function test_a_rep_creating_a_lead_still_keeps_it(): void
    {
        $rep = $this->rep;

        $this->actingAs($rep)->postJson('/api/v1/leads', [
            'full_name' => 'عميل المندوب',
            'phone' => '0551110002',
        ])->assertStatus(201);

        $this->assertSame($rep->id, Lead::firstWhere('full_name', 'عميل المندوب')->assigned_to);
    }

    public function test_a_manager_may_still_choose_the_owner(): void
    {
        $rep = $this->rep;

        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'عميل المدير',
            'phone' => '0551110003',
            'assigned_to' => $rep->uuid,
        ])->assertStatus(201);

        $this->assertSame($rep->id, Lead::firstWhere('full_name', 'عميل المدير')->assigned_to);
    }

    public function test_a_lead_cannot_be_created_for_a_deactivated_user(): void
    {
        $retired = $this->makeSales('mutaqaid@example.test');
        $retired->update(['is_active' => false]);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'عميل معطل',
            'phone' => '0551110004',
            'assigned_to' => $retired->uuid,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('leads', ['assigned_to' => $retired->id]);
    }

    public function test_a_lead_cannot_be_created_for_a_user_in_another_tenant(): void
    {
        $other = $this->provisionTenant('Rival Co');
        $stranger = $this->makeUser($other['tenant'], Role::Sales);

        $this->actingAs($this->t['admin'])->postJson('/api/v1/leads', [
            'full_name' => 'عميل مسرب',
            'phone' => '0551110005',
            'assigned_to' => $stranger->uuid,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('leads', ['assigned_to' => $stranger->id]);
    }

    private function makeSales(string $email): User
    {
        return User::create([
            'tenant_id' => $this->t['tenant']->id,
            'name' => 'زميل',
            'email' => $email,
            'password' => 'Password123!',
            'role' => Role::Sales,
            'is_active' => true,
        ]);
    }
}
