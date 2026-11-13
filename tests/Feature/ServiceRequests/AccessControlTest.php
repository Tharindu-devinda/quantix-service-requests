<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = User::factory()->manager()->create();

        $this->users = [
            'manager' => $manager,
            'other_manager' => User::factory()->manager()->create(),
            'employee' => User::factory()->employee()->create(['manager_id' => $manager->id]),
            'other_employee' => User::factory()->employee()->create(),
            'operations' => User::factory()->operations()->create(),
        ];
    }

    private function requestWithStatus(string $status): ServiceRequest
    {
        return ServiceRequest::factory()
            ->status(RequestStatus::from($status))
            ->create(['requester_id' => $this->users['employee']->id]);
    }

    /**
     * Each row: acting user, HTTP method, path suffix, request status, payload.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
     */
    public static function forbiddenActions(): array
    {
        $edit = ['title' => 'New title', 'description' => 'New description', 'category' => 'access'];
        $assign = ['assignee_id' => 'ops']; // replaced with a real id inside the test

        return [
            'another employee edits' => ['other_employee', 'putJson', '', 'draft', $edit],
            'manager edits' => ['manager', 'putJson', '', 'draft', $edit],
            'operations edits' => ['operations', 'putJson', '', 'draft', $edit],
            'owner edits a submitted request' => ['employee', 'putJson', '', 'submitted', $edit],
            'owner edits a rejected request' => ['employee', 'putJson', '', 'rejected', $edit],
            'owner edits a completed request' => ['employee', 'putJson', '', 'completed', $edit],

            'another employee submits' => ['other_employee', 'postJson', '/submit', 'draft', []],
            'manager submits' => ['manager', 'postJson', '/submit', 'draft', []],
            'operations submits' => ['operations', 'postJson', '/submit', 'draft', []],

            'requester decides' => ['employee', 'postJson', '/decision', 'submitted', ['decision' => 'approve']],
            'manager of another team decides' => ['other_manager', 'postJson', '/decision', 'submitted', ['decision' => 'approve']],
            'operations decides' => ['operations', 'postJson', '/decision', 'submitted', ['decision' => 'approve']],

            'employee assigns' => ['employee', 'postJson', '/assign', 'approved', $assign],
            'manager assigns' => ['manager', 'postJson', '/assign', 'approved', $assign],

            'employee changes status' => ['employee', 'postJson', '/status', 'assigned', ['status' => 'in_progress']],
            'manager changes status' => ['manager', 'postJson', '/status', 'assigned', ['status' => 'in_progress']],

            'another employee comments' => ['other_employee', 'postJson', '/comments', 'approved', ['body' => 'Hello']],
            'manager of another team comments' => ['other_manager', 'postJson', '/comments', 'approved', ['body' => 'Hello']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('forbiddenActions')]
    public function test_forbidden_actions_return_403_and_change_nothing(
        string $actor,
        string $method,
        string $suffix,
        string $status,
        array $payload,
    ): void {
        Notification::fake();
        $request = $this->requestWithStatus($status);
        Sanctum::actingAs($this->users[$actor]);

        if (($payload['assignee_id'] ?? null) === 'ops') {
            $payload['assignee_id'] = $this->users['operations']->id;
        }

        $this->{$method}("/api/requests/{$request->id}{$suffix}", $payload)->assertForbidden();

        $this->assertSame($status, $request->fresh()->status->value);
        $this->assertDatabaseCount('request_activities', 0);
        $this->assertDatabaseCount('request_comments', 0);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'me' => ['getJson', '/api/me'],
            'logout' => ['postJson', '/api/logout'],
            'list' => ['getJson', '/api/requests'],
            'create' => ['postJson', '/api/requests'],
            'show' => ['getJson', '/api/requests/{id}'],
            'update' => ['putJson', '/api/requests/{id}'],
            'submit' => ['postJson', '/api/requests/{id}/submit'],
            'decision' => ['postJson', '/api/requests/{id}/decision'],
            'assign' => ['postJson', '/api/requests/{id}/assign'],
            'status' => ['postJson', '/api/requests/{id}/status'],
            'comments' => ['postJson', '/api/requests/{id}/comments'],
            'notifications' => ['getJson', '/api/notifications'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_guests_get_401_on_every_protected_endpoint(string $method, string $path): void
    {
        $request = $this->requestWithStatus('draft');

        $this->{$method}(str_replace('{id}', (string) $request->id, $path))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workflowSuffixes(): array
    {
        return [
            'submit' => ['/submit'],
            'decision' => ['/decision'],
            'assign' => ['/assign'],
            'status' => ['/status'],
            'comments' => ['/comments'],
        ];
    }

    #[DataProvider('workflowSuffixes')]
    public function test_workflow_endpoints_return_404_for_a_missing_request(string $suffix): void
    {
        Sanctum::actingAs($this->users['operations']);

        $this->postJson("/api/requests/999999{$suffix}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Resource not found.']);
    }

    public function test_authorization_runs_before_validation(): void
    {
        $request = $this->requestWithStatus('submitted');
        Sanctum::actingAs($this->users['employee']);

        $this->postJson("/api/requests/{$request->id}/decision", ['decision' => 'nonsense'])
            ->assertForbidden();
    }
}