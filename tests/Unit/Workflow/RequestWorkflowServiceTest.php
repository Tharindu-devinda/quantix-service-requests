<?php

namespace Tests\Unit\Workflow;

use App\Enums\RequestStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestWorkflowServiceTest extends TestCase
{
    use RefreshDatabase;

    private RequestWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflow = app(RequestWorkflowService::class);
    }

    public function test_create_makes_a_draft_and_logs_it(): void
    {
        $employee = User::factory()->employee()->create();

        $request = $this->workflow->create($employee, [
            'title' => 'New laptop',
            'description' => 'Too slow.',
            'category' => 'equipment',
        ]);

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'action' => 'created',
            'user_id' => $employee->id,
        ]);
    }

    public function test_submit_sets_status_timestamp_and_logs_the_actor(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);

        $updated = $this->workflow->submit($request, $employee);

        $this->assertSame(RequestStatus::Submitted, $updated->status);
        $this->assertNotNull($updated->submitted_at);
        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'user_id' => $employee->id,
            'action' => 'submitted',
            'from_status' => 'draft',
            'to_status' => 'submitted',
        ]);
    }

    public function test_full_happy_path_from_draft_to_completed(): void
    {
        $employee = User::factory()->employee()->create();
        $manager = User::factory()->manager()->create();
        $operations = User::factory()->operations()->create();
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);

        $this->workflow->submit($request, $employee);
        $this->workflow->approve($request, $manager, 'Looks good.');
        $this->workflow->assign($request, $operations, $operations);
        $this->workflow->start($request, $operations);
        $final = $this->workflow->complete($request, $operations, 'All done.');

        $this->assertSame(RequestStatus::Completed, $final->status);
        $this->assertSame($operations->id, $final->assigned_to);
        $this->assertNotNull($final->completed_at);
        $this->assertSame(5, $final->activities()->count());
    }

    public function test_returned_request_can_be_resubmitted(): void
    {
        $employee = User::factory()->employee()->create();
        $manager = User::factory()->manager()->create();
        $request = ServiceRequest::factory()->submitted()->create(['requester_id' => $employee->id]);

        $this->workflow->returnToRequester($request, $manager, 'Add more detail.');
        $updated = $this->workflow->submit($request, $employee);

        $this->assertSame(RequestStatus::Submitted, $updated->status);
    }

    public function test_comment_is_stored_on_the_activity(): void
    {
        $manager = User::factory()->manager()->create();
        $request = ServiceRequest::factory()->submitted()->create();

        $this->workflow->returnToRequester($request, $manager, 'Please add a budget code.');

        $this->assertDatabaseHas('request_activities', [
            'service_request_id' => $request->id,
            'action' => 'returned',
            'comment' => 'Please add a budget code.',
        ]);
    }

    public function test_invalid_transition_throws_and_changes_nothing(): void
    {
        $manager = User::factory()->manager()->create();
        $request = ServiceRequest::factory()->create(); // draft

        try {
            $this->workflow->approve($request, $manager);
            $this->fail('Expected InvalidTransitionException was not thrown.');
        } catch (InvalidTransitionException $e) {
            $this->assertStringContainsString("'draft' to 'approved'", $e->getMessage());
        }

        $this->assertSame(RequestStatus::Draft, $request->fresh()->status);
        $this->assertDatabaseCount('request_activities', 0);
    }

    public function test_cannot_skip_steps(): void
    {
        $operations = User::factory()->operations()->create();
        $request = ServiceRequest::factory()->status(RequestStatus::Approved)->create();

        $this->expectException(InvalidTransitionException::class);

        $this->workflow->complete($request, $operations); // approved -> completed is not allowed
    }

    public function test_rejected_request_is_final(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->status(RequestStatus::Rejected)->create(['requester_id' => $employee->id]);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('can no longer be changed');

        $this->workflow->submit($request, $employee);
    }

    public function test_completed_request_is_final(): void
    {
        $operations = User::factory()->operations()->create();
        $request = ServiceRequest::factory()->status(RequestStatus::Completed)->create();

        $this->expectException(InvalidTransitionException::class);

        $this->workflow->start($request, $operations);
    }

    public function test_update_is_rejected_when_the_request_is_not_editable(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->submitted()->create([
            'requester_id' => $employee->id,
            'title' => 'Original',
        ]);

        try {
            $this->workflow->update($request, $employee, ['title' => 'Changed']);
            $this->fail('Expected InvalidTransitionException was not thrown.');
        } catch (InvalidTransitionException $e) {
            $this->assertStringContainsString("'submitted' status cannot be edited", $e->getMessage());
        }

        $this->assertSame('Original', $request->fresh()->title);
    }
}
