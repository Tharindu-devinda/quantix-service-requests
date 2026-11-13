<?php

namespace Tests\Feature;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401_json(): void
    {
        $this->getJson('/api/requests')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_json_is_returned_even_without_an_accept_header(): void
    {
        // Plain get(), no "Accept: application/json".
        $this->get('/api/requests')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_validation_errors_are_json_even_without_an_accept_header(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->post('/api/requests', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['title', 'description', 'category']]);
    }

    public function test_forbidden_returns_403_json(): void
    {
        $request = ServiceRequest::factory()->create();
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson("/api/requests/{$request->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => 'This action is unauthorized.']);
    }

    public function test_missing_record_returns_404_without_leaking_the_model_name(): void
    {
        Sanctum::actingAs(User::factory()->employee()->create());

        $this->getJson('/api/requests/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_unknown_route_returns_404_json(): void
    {
        $this->get('/api/does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_wrong_http_method_returns_405_json(): void
    {
        $this->postJson('/api/me')
            ->assertStatus(405)
            ->assertExactJson(['message' => 'Method not allowed.']);
    }

    public function test_invalid_transition_returns_422_with_a_message(): void
    {
        $manager = User::factory()->manager()->create();
        $employee = User::factory()->employee()->create(['manager_id' => $manager->id]);
        $request = ServiceRequest::factory()->create(['requester_id' => $employee->id]); // draft

        Sanctum::actingAs($manager);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'approve'])
            ->assertUnprocessable()
            ->assertExactJson(['message' => "A request cannot move from 'draft' to 'approved'."]);
    }

    public function test_too_many_login_attempts_return_429_json(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too many requests. Please try again later.']);
    }

    public function test_unexpected_errors_return_500_json_without_details(): void
    {
        config(['app.debug' => false]);

        Route::get('/api/test-boom', fn () => throw new \RuntimeException('secret internal detail'));

        $this->getJson('/api/test-boom')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Server Error']);
    }
}
