<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreateServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'New laptop',
            'description' => 'My current laptop is too slow.',
            'category' => 'equipment',
            'priority' => 'high',
        ], $overrides);
    }

    public function test_employee_can_create_a_draft_request(): void
    {
        $employee = User::factory()->employee()->create();
        Sanctum::actingAs($employee);

        $this->postJson('/api/requests', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.requester.id', $employee->id);

        $this->assertDatabaseHas('service_requests', [
            'title' => 'New laptop',
            'requester_id' => $employee->id,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('request_activities', [
            'action' => 'created',
            'user_id' => $employee->id,
            'to_status' => 'draft',
        ]);
    }

    public function test_priority_defaults_to_medium(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->postJson('/api/requests', $this->payload(['priority' => null]))
            ->assertUnprocessable();

        $data = $this->payload();
        unset($data['priority']);

        $this->postJson('/api/requests', $data)
            ->assertCreated()
            ->assertJsonPath('data.priority', 'medium');
    }

    public function test_validation_errors_return_422(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->postJson('/api/requests', ['category' => 'nonsense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'category']);
    }

    public function test_manager_cannot_create_a_request(): void
    {
        Sanctum::actingAs(User::factory()->manager()->create());

        $this->postJson('/api/requests', $this->payload())->assertForbidden();
    }

    public function test_operations_user_cannot_create_a_request(): void
    {
        Sanctum::actingAs(User::factory()->operations()->create());

        $this->postJson('/api/requests', $this->payload())->assertForbidden();
    }

    public function test_guest_cannot_create_a_request(): void
    {
        $this->postJson('/api/requests', $this->payload())->assertUnauthorized();
    }
}