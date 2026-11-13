<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Notifications\RequestStatusChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class RequestNotifier
{
    public function statusChanged(
        ServiceRequest $serviceRequest,
        RequestStatus $from,
        User $actor,
        ?string $comment = null,
    ): void {
        $serviceRequest->loadMissing(['requester.manager', 'assignee']);

        $recipients = $this->recipientsFor($serviceRequest)
            ->filter()
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send(
            $recipients,
            new RequestStatusChanged($serviceRequest, $from, $serviceRequest->status, $actor, $comment),
        );
    }

    /**
     * @return Collection<int, User|null>
     */
    private function recipientsFor(ServiceRequest $serviceRequest): Collection
    {
        $requester = $serviceRequest->requester;
        $manager = $requester->manager;

        return match ($serviceRequest->status) {
            RequestStatus::Submitted => collect([$manager]),
            RequestStatus::Approved => collect([$requester])
                ->merge(User::where('role', UserRole::Operations->value)->get()),
            RequestStatus::Rejected,
            RequestStatus::Returned,
            RequestStatus::InProgress => collect([$requester]),
            RequestStatus::Assigned => collect([$serviceRequest->assignee, $requester]),
            RequestStatus::Completed => collect([$requester, $manager]),
            RequestStatus::Draft => collect(),
        };
    }
}
