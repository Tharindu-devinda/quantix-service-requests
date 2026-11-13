<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommentTest extends TestCase
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

    public function test_requester_can_comment_on_their_own_request(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Adding more detail.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Adding more detail.')
            ->assertJsonPath('data.user.id', $this->employee->id);

        $this->assertDatabaseHas('request_comments', [
            'service_request_id' => $request->id,
            'user_id' => $this->employee->id,
            'body' => 'Adding more detail.',
        ]);
    }

    public function test_manager_can_comment_on_a_submitted_request_from_their_team(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Which model do you need?'])
            ->assertCreated();
    }

    public function test_operations_can_comment_on_an_approved_request(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);
        Sanctum::actingAs($this->operations);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'We will start tomorrow.'])
            ->assertCreated();
    }

    public function test_comments_appear_on_the_request_details_oldest_first(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Submitted);

        Sanctum::actingAs($this->employee);
        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'First'])->assertCreated();

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Second'])->assertCreated();

        $this->getJson("/api/requests/{$request->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.comments')
            ->assertJsonPath('data.comments.0.body', 'First')
            ->assertJsonPath('data.comments.1.body', 'Second');
    }

    public function test_user_who_cannot_see_the_request_cannot_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Approved);

        foreach ([
            User::factory()->employee()->create(),   // another employee
            User::factory()->manager()->create(),     // manager of another team
        ] as $outsider) {
            Sanctum::actingAs($outsider);

            $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Hello'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('request_comments', 0);
    }

    public function test_manager_and_operations_cannot_comment_on_a_draft(): void
    {
        $draft = $this->requestWithStatus(RequestStatus::Draft);

        foreach ([$this->manager, $this->operations] as $user) {
            Sanctum::actingAs($user);

            $this->postJson("/api/requests/{$draft->id}/comments", ['body' => 'Hello'])
                ->assertForbidden();
        }
    }

    public function test_comment_body_is_required_and_limited(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);
        Sanctum::actingAs($this->employee);

        $this->postJson("/api/requests/{$request->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);
    }

    public function test_guest_cannot_comment(): void
    {
        $request = $this->requestWithStatus(RequestStatus::Draft);

        $this->postJson("/api/requests/{$request->id}/comments", ['body' => 'Hello'])
            ->assertUnauthorized();
    }

    public function test_commenting_on_a_missing_request_returns_404(): void
    {
        Sanctum::actingAs($this->employee);

        $this->postJson('/api/requests/999999/comments', ['body' => 'Hello'])->assertNotFound();
    }
}
