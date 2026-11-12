<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\RequestActivity;
use App\Models\RequestComment;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShowServiceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_request_with_comments_and_activity(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]);
        RequestComment::factory()->create(['service_request_id' => $request->id]);
        RequestActivity::factory()->create(['service_request_id' => $request->id]);

        Sanctum::actingAs($employee);

        $this->getJson("/api/requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $request->id)
            ->assertJsonCount(1, 'data.comments')
            ->assertJsonCount(1, 'data.activities');
    }

    public function test_manager_can_view_a_submitted_request_from_a_direct_report(): void
    {
        $manager = User::factory()->manager()->create();
        $report = User::factory()->employee()->create(['manager_id' => $manager->id]);
        $request = ServiceRequest::factory()->submitted()->create(['requester_id' => $report->id]);

        Sanctum::actingAs($manager);

        $this->getJson("/api/requests/{$request->id}")->assertOk();
    }

    public function test_employee_cannot_view_another_employees_request(): void
    {
        $request = ServiceRequest::factory()->create();

        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson("/api/requests/{$request->id}")->assertForbidden();
    }

    public function test_manager_cannot_view_a_request_from_another_team(): void
    {
        $manager = User::factory()->manager()->create();
        $request = ServiceRequest::factory()->submitted()->create();

        Sanctum::actingAs($manager);

        $this->getJson("/api/requests/{$request->id}")->assertForbidden();
    }

    public function test_missing_request_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/requests/999999')->assertNotFound();
    }
}