<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    private User $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->employee = User::factory()->employee()->create(['manager_id' => $this->manager->id]);
        $this->operations = User::factory()->operations()->create();
    }

    private function requestWithStatus(RequestStatus $status): ServiceRequest
    {
        return ServiceRequest::factory()
            ->status($status)
            ->create(['requester_id' => $this->employee->id]);
    }

    public function test_employee_can_submit_a_draft(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_manager_can_approve(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_manager_can_reject_with_a_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'reject',
            'comment' => 'Not in budget.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'action' => 'rejected',
            'comment' => 'Not in budget.',
        ]);
    }

    public function test_returning_a_request_requires_a_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'return'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['comment']);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'return',
            'comment' => 'Please add a budget code.',
        ])->assertOk()->assertJsonPath('data.status', 'returned');
    }

    public function test_operations_can_assign_an_owner(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $this->operations->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assignee.id', $this->operations->id);
    }

    public function test_operations_can_start_and_complete_work(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Assigned);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->postJson("/api/requests/{$request->id}/status", ['status' => 'completed', 'comment' => 'Done.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($request->fresh()->completed_at);
    }

    public function test_full_journey_across_all_three_roles(): void
    {
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/requests', [
            'title' => 'New monitor',
            'description' => 'Need a second monitor.',
            'category' => 'equipment',
            'submit' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])->assertOk();

        Sanctum::actingAs($this->operations);
        $this->postJson("/api/requests/{$id}/assign", ['assignee_id' => $this->operations->id])->assertOk();
        $this->postJson("/api/requests/{$id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/requests/{$id}/status", ['status' => 'completed'])->assertOk();

        $this->getJson("/api/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonCount(6, 'data.activities'); // created, submitted, approved, assigned, started, completed
    }

    public function test_manager_of_another_team_cannot_decide(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs(User::factory()->manager()->create());

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_employee_cannot_decide(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertForbidden();
    }

    public function test_manager_cannot_assign(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $this->operations->id])
            ->assertForbidden();
    }

    public function test_invalid_transition_returns_422_with_a_clear_message(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "A request cannot move from 'draft' to 'approved'.");
    }

    public function test_assignee_must_be_an_operations_user(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $this->employee->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignee_id']);
    }

    public function test_status_endpoint_only_accepts_in_progress_or_completed(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Assigned);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/status", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}