<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListServiceRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_requests(): void
    {
        $this->getJson('/api/requests')->assertUnauthorized();
    }

    public function test_employee_sees_only_their_own_requests(): void
    {
        $alice = User::factory()->employee()->create();
        $bob = User::factory()->employee()->create();

        ServiceRequest::factory()->count(2)->create(['requester_id' => $alice->id]);
        ServiceRequest::factory()->create(['requester_id' => $bob->id]);

        Sanctum::actingAs($alice);

        $this->getJson('/api/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_manager_sees_only_non_draft_requests_from_direct_reports(): void
    {
        $manager = User::factory()->manager()->create();
        $report = User::factory()->employee()->create(['manager_id' => $manager->id]);
        $outsider = User::factory()->employee()->create();

        ServiceRequest::factory()->submitted()->create(['requester_id' => $report->id]);
        ServiceRequest::factory()->create(['requester_id' => $report->id]); // draft: hidden
        ServiceRequest::factory()->submitted()->create(['requester_id' => $outsider->id]); // other team

        Sanctum::actingAs($manager);

        $this->getJson('/api/requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_operations_user_sees_only_approved_and_later_requests(): void
    {
        ServiceRequest::factory()->status(RequestStatus::Submitted)->create();
        ServiceRequest::factory()->status(RequestStatus::Rejected)->create();
        ServiceRequest::factory()->status(RequestStatus::Approved)->create();
        ServiceRequest::factory()->status(RequestStatus::Completed)->create();

        Sanctum::actingAs(User::factory()->operations()->create());

        $this->getJson('/api/requests')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_filters_by_status(): void
    {
        $employee = User::factory()->employee()->create();
        ServiceRequest::factory()->create(['requester_id' => $employee->id]);
        ServiceRequest::factory()->submitted()->create(['requester_id' => $employee->id]);

        Sanctum::actingAs($employee);

        $this->getJson('/api/requests?status=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'submitted');
    }

    public function test_it_searches_title_and_description(): void
    {
        $employee = User::factory()->employee()->create();
        ServiceRequest::factory()->create(['requester_id' => $employee->id, 'title' => 'VPN access']);
        ServiceRequest::factory()->create(['requester_id' => $employee->id, 'title' => 'New chair']);

        Sanctum::actingAs($employee);

        $this->getJson('/api/requests?search=vpn')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'VPN access');
    }

    public function test_it_sorts_by_title(): void
    {
        $employee = User::factory()->employee()->create();
        ServiceRequest::factory()->create(['requester_id' => $employee->id, 'title' => 'B request']);
        ServiceRequest::factory()->create(['requester_id' => $employee->id, 'title' => 'A request']);

        Sanctum::actingAs($employee);

        $this->getJson('/api/requests?sort=title&direction=asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A request');
    }

    public function test_it_paginates_and_returns_meta(): void
    {
        $employee = User::factory()->employee()->create();
        ServiceRequest::factory()->count(3)->create(['requester_id' => $employee->id]);

        Sanctum::actingAs($employee);

        $this->getJson('/api/requests?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    public function test_invalid_filters_return_422(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/requests?status=nonsense')->assertUnprocessable();
        $this->getJson('/api/requests?sort=password')->assertUnprocessable();
        $this->getJson('/api/requests?per_page=1000')->assertUnprocessable();
    }
}