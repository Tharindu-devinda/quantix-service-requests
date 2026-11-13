<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UpdateServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return [
            'title' => 'Updated title',
            'description' => 'Updated description.',
            'category' => 'access',
            'priority' => 'low',
        ];
    }

    public function test_owner_can_edit_a_draft(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
        Sanctum::actingAs($employee);

        $this->putJson("/api/requests/{$request->id}", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title');

        $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'title' => 'Updated title']);
        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'action' => 'updated',
            'user_id' => $employee->id,
        ]);
    }

    public function test_owner_can_edit_a_returned_request(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()
            ->status(RequestStatus::Returned)
            ->create(['requester_id' => $employee->id]);
        Sanctum::actingAs($employee);

        $this->putJson("/api/requests/{$request->id}", $this->payload())->assertOk();
    }

    public function test_owner_cannot_edit_a_submitted_request(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->submitted()->create(['requester_id' => $employee->id]);
        Sanctum::actingAs($employee);

        $this->putJson("/api/requests/{$request->id}", $this->payload())->assertForbidden();
    }

    public function test_another_employee_cannot_edit_the_request(): void
    {
        $request = ServiceRequest::factory()->create();
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->putJson("/api/requests/{$request->id}", $this->payload())->assertForbidden();
    }

    public function test_edit_validates_input(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
        Sanctum::actingAs($employee);

        $this->putJson("/api/requests/{$request->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'description', 'category']);
    }
}