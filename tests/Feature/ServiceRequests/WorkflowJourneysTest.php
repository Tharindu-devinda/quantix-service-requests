<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\RequestActivity;
use App\Models\RequestComment;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowJourneysTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->manager()->create();
        $this->employee = User::factory()->employee()->create(['manager_id' => $this->manager->id]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'VPN access',
            'description' => 'Need VPN for the new project.',
            'category' => 'access',
        ], $overrides);
    }

    public function test_returned_request_can_be_fixed_resubmitted_and_approved(): void
    {
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/requests', $this->payload(['submit' => true]))
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$id}/decision", [
            'decision' => 'return',
            'comment' => 'Add a budget code.',
        ])->assertOk()->assertJsonPath('data.status', 'returned');

        Sanctum::actingAs($this->employee);
        $this->putJson("/api/requests/{$id}", $this->payload(['description' => 'Budget code 4421.']))
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');
        $this->postJson("/api/requests/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])->assertOk();

        $response = $this->getJson("/api/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(
            ['created', 'submitted', 'returned', 'updated', 'submitted', 'approved'],
            $response->json('data.activities.*.action'),
        );
    }

    public function test_rejection_is_final(): void
    {
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/requests', $this->payload(['submit' => true]))->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$id}/decision", [
            'decision' => 'reject',
            'comment' => 'Not in budget.',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Manager cannot change their mind.
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approve'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This request is rejected and can no longer be changed.');

        // Employee can neither edit nor resubmit.
        Sanctum::actingAs($this->employee);
        $this->putJson("/api/requests/{$id}", $this->payload())->assertForbidden();
        $this->postJson("/api/requests/{$id}/submit")->assertUnprocessable();
    }

    public function test_activity_trail_records_who_when_and_the_comment(): void
    {
        $request = ServiceRequest::factory()->submitted()->create(['requester_id' => $this->employee->id]);

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$request->id}/decision", [
            'decision' => 'reject',
            'comment' => 'Not in budget.',
        ])->assertOk();

        $activity = $this->getJson("/api/requests/{$request->id}")
            ->assertOk()
            ->json('data.activities.0');

        $this->assertSame('rejected', $activity['action']);
        $this->assertSame('submitted', $activity['from_status']);
        $this->assertSame('rejected', $activity['to_status']);
        $this->assertSame('Not in budget.', $activity['comment']);
        $this->assertSame($this->manager->id, $activity['user']['id']);
        $this->assertNotNull($activity['created_at']);
    }

    public function test_history_with_identical_timestamps_keeps_insertion_order(): void
    {
        $request = ServiceRequest::factory()->create(['requester_id' => $this->employee->id]);
        $sameSecond = now();

        foreach (['one', 'two', 'three'] as $body) {
            RequestComment::factory()->create([
                'service_request_id' => $request->id,
                'body' => $body,
                'created_at' => $sameSecond,
            ]);

            RequestActivity::factory()->create([
                'service_request_id' => $request->id,
                'action' => $body,
                'created_at' => $sameSecond,
            ]);
        }

        Sanctum::actingAs($this->employee);

        $response = $this->getJson("/api/requests/{$request->id}")->assertOk();

        $this->assertSame(['one', 'two', 'three'], $response->json('data.comments.*.body'));
        $this->assertSame(['one', 'two', 'three'], $response->json('data.activities.*.action'));
    }
}