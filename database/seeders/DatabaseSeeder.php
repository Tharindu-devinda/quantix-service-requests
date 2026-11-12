<?php

namespace Database\Seeders;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Status path each seeded request has travelled (after creation).
     *
     * @var array<string, array<int, RequestStatus>>
     */
    private const PATHS = [
        'draft' => [],
        'submitted' => [RequestStatus::Submitted],
        'returned' => [RequestStatus::Submitted, RequestStatus::Returned],
        'approved' => [RequestStatus::Submitted, RequestStatus::Approved],
        'rejected' => [RequestStatus::Submitted, RequestStatus::Rejected],
        'assigned' => [RequestStatus::Submitted, RequestStatus::Approved, RequestStatus::Assigned],
        'in_progress' => [RequestStatus::Submitted, RequestStatus::Approved, RequestStatus::Assigned, RequestStatus::InProgress],
        'completed' => [RequestStatus::Submitted, RequestStatus::Approved, RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Completed],
    ];

    public function run(): void
    {
        $manager = User::factory()->manager()->create([
            'name' => 'Maya Manager',
            'email' => 'manager@example.com',
        ]);

        $alice = User::factory()->employee()->create([
            'name' => 'Alice Employee',
            'email' => 'alice@example.com',
            'manager_id' => $manager->id,
        ]);

        $bob = User::factory()->employee()->create([
            'name' => 'Bob Employee',
            'email' => 'bob@example.com',
            'manager_id' => $manager->id,
        ]);

        $operations = User::factory()->operations()->create([
            'name' => 'Oscar Operations',
            'email' => 'operations@example.com',
        ]);

        $samples = [
            [$alice, RequestStatus::Draft, 'Request a standing desk'],
            [$bob, RequestStatus::Draft, 'Replacement headset'],
            [$alice, RequestStatus::Submitted, 'VPN access for new project'],
            [$bob, RequestStatus::Submitted, 'Broken laptop screen'],
            [$alice, RequestStatus::Returned, 'Software licence for design tool'],
            [$bob, RequestStatus::Approved, 'Access to shared drive'],
            [$alice, RequestStatus::Rejected, 'Second external monitor'],
            [$bob, RequestStatus::Assigned, 'Air conditioning repair in room 4'],
            [$alice, RequestStatus::InProgress, 'Install development tools'],
            [$bob, RequestStatus::Completed, 'Reset email password'],
        ];

        foreach ($samples as [$requester, $status, $title]) {
            $this->seedRequest($requester, $manager, $operations, $status, $title);
        }
    }

    private function seedRequest(
        User $requester,
        User $manager,
        User $operations,
        RequestStatus $status,
        string $title,
    ): void {
        $request = ServiceRequest::factory()->create([
            'requester_id' => $requester->id,
            'title' => $title,
            'status' => $status,
            'submitted_at' => $status === RequestStatus::Draft ? null : now()->subDays(3),
            'assigned_to' => in_array($status, [
                RequestStatus::Assigned,
                RequestStatus::InProgress,
                RequestStatus::Completed,
            ], true) ? $operations->id : null,
            'completed_at' => $status === RequestStatus::Completed ? now() : null,
        ]);

        RequestActivity::create([
            'service_request_id' => $request->id,
            'user_id' => $requester->id,
            'action' => 'created',
            'from_status' => null,
            'to_status' => RequestStatus::Draft,
        ]);

        $from = RequestStatus::Draft;

        foreach (self::PATHS[$status->value] as $to) {
            RequestActivity::create([
                'service_request_id' => $request->id,
                'user_id' => $this->actorFor($to, $requester, $manager, $operations)->id,
                'action' => $to->value,
                'from_status' => $from,
                'to_status' => $to,
                'comment' => $to === RequestStatus::Returned ? 'Please add more detail.' : null,
            ]);

            $from = $to;
        }
    }

    private function actorFor(
        RequestStatus $to,
        User $requester,
        User $manager,
        User $operations,
    ): User {
        return match ($to) {
            RequestStatus::Submitted => $requester,
            RequestStatus::Approved, RequestStatus::Rejected, RequestStatus::Returned => $manager,
            default => $operations,
        };
    }
}