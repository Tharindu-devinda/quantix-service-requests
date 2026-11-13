<?php

namespace Tests\Feature\Notifications;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestNotificationTest extends TestCase
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

    public function test_notification_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new RequestStatusChanged(
            $this->requestWithStatus(RequestStatus::Submitted),
            RequestStatus::Draft,
            RequestStatus::Submitted,
            $this->employee,
        ));
    }

    public function test_a_queue_job_is_dispatched_when_a_request_is_submitted(): void
    {
        Queue::fake();
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/submit")->assertOk();

        Queue::assertPushed(SendQueuedNotifications::class, 1);
    }

    public function test_submit_notifies_the_manager_only(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/submit")->assertOk();

        Notification::assertSentTo(
            $this->manager,
            RequestStatusChanged::class,
            fn (RequestStatusChanged $n) => $n->to === RequestStatus::Submitted
                && $n->serviceRequest->is($request),
        );
        Notification::assertNotSentTo($this->employee, RequestStatusChanged::class);
        Notification::assertNotSentTo($this->operations, RequestStatusChanged::class);
    }

    public function test_approval_notifies_the_requester_and_operations_but_not_the_actor(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])->assertOk();

        Notification::assertSentTo($this->employee, RequestStatusChanged::class);
        Notification::assertSentTo($this->operations, RequestStatusChanged::class);
        Notification::assertNotSentTo($this->manager, RequestStatusChanged::class);
    }

    public function test_return_notifies_the_requester_with_the_comment(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'return',
            'comment' => 'Please add a budget code.',
        ])->assertOk();

        Notification::assertSentTo(
            $this->employee,
            RequestStatusChanged::class,
            fn (RequestStatusChanged $n) => $n->comment === 'Please add a budget code.'
                && $n->toArray($this->employee)['to_status'] === 'returned',
        );
    }

    public function test_assignment_notifies_the_assignee_and_the_requester(): void
    {
        Notification::fake();
        $assignee = User::factory()->operations()->create();
        $request = $this->requestWithStatus(RequestStatus::Approved);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/assign", ['assignee_id' => $assignee->id])->assertOk();

        Notification::assertSentTo($assignee, RequestStatusChanged::class);
        Notification::assertSentTo($this->employee, RequestStatusChanged::class);
        Notification::assertNotSentTo($this->operations, RequestStatusChanged::class); // the actor
    }

    public function test_completion_notifies_the_requester_and_their_manager(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::InProgress);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/status", ['status' => 'completed'])->assertOk();

        Notification::assertSentTo($this->employee, RequestStatusChanged::class);
        Notification::assertSentTo($this->manager, RequestStatusChanged::class);
    }

    public function test_nothing_is_sent_when_the_transition_is_invalid(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertUnprocessable();

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_request_is_only_edited(): void
    {
        Notification::fake();
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->putJson("/api/requests/{$request->id}", [
            'title' => 'Updated',
            'description' => 'Updated description.',
            'category' => 'access',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_notification_is_stored_in_the_database_and_listed_via_the_api(): void
    {
        config(['queue.default' => 'sync']);
        $request = $this->requestWithStatus(RequestStatus::Draft);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/requests/{$request->id}/submit")->assertOk();

        $this->assertCount(1, $this->manager->fresh()->notifications);

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.request_id', $request->id)
            ->assertJsonPath('data.0.data.to_status', 'submitted');
    }

    public function test_users_only_see_their_own_notifications(): void
    {
        config(['queue.default' => 'sync']);
        $request = $this->requestWithStatus(RequestStatus::Draft);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/requests/{$request->id}/submit")->assertOk();

        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guest_cannot_list_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }
}