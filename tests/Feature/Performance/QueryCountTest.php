<?php

namespace Tests\Feature\Performance;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\RequestComment;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_employee_list_does_not_run_extra_queries_per_row(): void
    {
        $employee = User::factory()->employee()->create();
        Sanctum::actingAs($employee);

        $make = fn (int $n) => ServiceRequest::factory()->count($n)->submitted()->create([
            'requester_id' => $employee->id,
            'assigned_to' => User::factory()->operations(),
        ]);

        $make(3);
        $this->getJson('/api/requests?per_page=50')->assertOk(); // warm-up

        $small = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(3, 'data'),
        );

        $make(12);

        $large = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(15, 'data'),
        );

        $this->assertSame($small, $large, 'The employee list runs extra queries per row (N+1).');
    }

    public function test_manager_list_does_not_run_extra_queries_per_row(): void
    {
        $manager = User::factory()->manager()->create();
        Sanctum::actingAs($manager);

        // A different requester and assignee on every row, to expose lazy loading.
        $make = fn (int $n) => ServiceRequest::factory()->count($n)->submitted()->create([
            'requester_id' => User::factory()->employee()->state(['manager_id' => $manager->id]),
            'assigned_to' => User::factory()->operations(),
        ]);

        $make(3);
        $this->getJson('/api/requests?per_page=50')->assertOk(); // warm-up

        $small = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(3, 'data'),
        );

        $make(12);

        $large = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(15, 'data'),
        );

        $this->assertSame($small, $large, 'The manager list runs extra queries per row (N+1).');
    }

    public function test_operations_list_does_not_run_extra_queries_per_row(): void
    {
        Sanctum::actingAs(User::factory()->operations()->create());

        $make = fn (int $n) => ServiceRequest::factory()->count($n)->status(RequestStatus::Approved)->create([
            'requester_id' => User::factory()->employee(),
            'assigned_to' => User::factory()->operations(),
        ]);

        $make(3);
        $this->getJson('/api/requests?per_page=50')->assertOk(); // warm-up

        $small = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(3, 'data'),
        );

        $make(12);

        $large = $this->countQueries(
            fn () => $this->getJson('/api/requests?per_page=50')->assertOk()->assertJsonCount(15, 'data'),
        );

        $this->assertSame($small, $large, 'The operations list runs extra queries per row (N+1).');
    }

    public function test_show_does_not_run_extra_queries_per_comment_or_activity(): void
    {
        $employee = User::factory()->employee()->create();
        $request = ServiceRequest::factory()->create([
            'requester_id' => $employee->id,
            'assigned_to' => User::factory()->operations(),
        ]);
        Sanctum::actingAs($employee);

        $add = function (int $n) use ($request) {
            RequestComment::factory()->count($n)->create(['service_request_id' => $request->id]);
            RequestActivity::factory()->count($n)->create(['service_request_id' => $request->id]);
        };

        $add(2);
        $this->getJson("/api/requests/{$request->id}")->assertOk();

        $small = $this->countQueries(
            fn () => $this->getJson("/api/requests/{$request->id}")->assertOk()->assertJsonCount(2, 'data.comments'),
        );

        $add(8);

        $large = $this->countQueries(
            fn () => $this->getJson("/api/requests/{$request->id}")->assertOk()->assertJsonCount(10, 'data.comments'),
        );

        $this->assertSame($small, $large, 'The detail endpoint runs extra queries per comment or activity (N+1).');
    }
}
