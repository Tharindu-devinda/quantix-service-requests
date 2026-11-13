<?php

namespace Tests\Unit\Policies;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRequestPolicyTest extends TestCase
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

    public function test_owner_can_update_draft_and_returned_requests(): void
    {
        $this->assertTrue($this->employee->can('update', $this->requestWithStatus(RequestStatus::Draft)));
        $this->assertTrue($this->employee->can('update', $this->requestWithStatus(RequestStatus::Returned)));
    }

    public function test_owner_cannot_update_requests_in_any_other_status(): void
    {
        $others = [
            RequestStatus::Submitted,
            RequestStatus::Approved,
            RequestStatus::Rejected,
            RequestStatus::Assigned,
            RequestStatus::InProgress,
            RequestStatus::Completed,
        ];

        foreach ($others as $status) {
            $this->assertFalse(
                $this->employee->can('update', $this->requestWithStatus($status)),
                "Owner should not edit a {$status->value} request",
            );
        }
    }

    public function test_non_owners_cannot_update(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);
        $otherEmployee = User::factory()->employee()->create();

        $this->assertFalse($otherEmployee->can('update', $request));
        $this->assertFalse($this->manager->can('update', $request));
        $this->assertFalse($this->operations->can('update', $request));
    }

    public function test_only_the_owner_can_submit(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);

        $this->assertTrue($this->employee->can('submit', $request));
        $this->assertFalse(User::factory()->employee()->create()->can('submit', $request));
        $this->assertFalse($this->manager->can('submit', $request));
        $this->assertFalse($this->operations->can('submit', $request));
    }

    public function test_manager_can_decide_on_a_direct_reports_request(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);

        $this->assertTrue($this->manager->can('decide', $request));
    }

    public function test_manager_of_another_team_cannot_decide(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        $otherManager = User::factory()->manager()->create();

        $this->assertFalse($otherManager->can('decide', $request));
    }

    public function test_employees_and_operations_cannot_decide(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);

        $this->assertFalse($this->employee->can('decide', $request));
        $this->assertFalse($this->operations->can('decide', $request));
    }

    public function test_operations_can_assign_and_change_status_on_visible_requests(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);

        $this->assertTrue($this->operations->can('assign', $request));
        $this->assertTrue($this->operations->can('changeStatus', $request));
    }

    public function test_operations_cannot_touch_requests_they_cannot_see(): void
    {
        $draft = $this->requestWithStatus(RequestStatus::Draft);
        $submitted = $this->requestWithStatus(RequestStatus::Submitted);

        $this->assertFalse($this->operations->can('assign', $draft));
        $this->assertFalse($this->operations->can('changeStatus', $submitted));
    }

    public function test_employees_and_managers_cannot_assign_or_change_status(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);

        foreach ([$this->employee, $this->manager] as $user) {
            $this->assertFalse($user->can('assign', $request));
            $this->assertFalse($user->can('changeStatus', $request));
        }
    }

    public function test_participants_can_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);

        $this->assertTrue($this->employee->can('comment', $request));  // requester
        $this->assertTrue($this->manager->can('comment', $request));   // their manager
        $this->assertTrue($this->operations->can('comment', $request)); // operations (approved+)
    }

    public function test_outsiders_cannot_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);

        $this->assertFalse(User::factory()->employee()->create()->can('comment', $request));
        $this->assertFalse(User::factory()->manager()->create()->can('comment', $request));
    }

    public function test_manager_and_operations_cannot_comment_on_a_draft(): void
    {
        $draft = $this->requestWithStatus(RequestStatus::Draft);

        $this->assertTrue($this->employee->can('comment', $draft));
        $this->assertFalse($this->manager->can('comment', $draft));
        $this->assertFalse($this->operations->can('comment', $draft));
    }
}
