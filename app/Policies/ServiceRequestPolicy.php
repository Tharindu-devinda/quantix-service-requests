<?php

namespace App\Policies;

use App\Models\ServiceRequest;
use App\Models\User;

class ServiceRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ServiceRequest $serviceRequest): bool
    {
        // Reuse the list scope so "can see in list" and "can open" never disagree.
        return ServiceRequest::query()
            ->visibleTo($user)
            ->whereKey($serviceRequest->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->isEmployee();
    }

    /**
     * Employees edit only their own request, and only in Draft or Returned.
     */
    public function update(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->isOwner($user, $serviceRequest)
            && $serviceRequest->status->isEditable();
    }

    /**
     * Who may send it for review. Whether the status allows it is the service's job (422).
     */
    public function submit(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->isOwner($user, $serviceRequest);
    }

    /**
     * Approve, reject or return: managers, for their own direct reports only.
     */
    public function decide(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isManager()
            && $this->isManagerOf($user, $serviceRequest);
    }

    public function assign(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isOperations()
            && $this->view($user, $serviceRequest);
    }

    /**
     * Start or complete work.
     */
    public function changeStatus(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isOperations()
            && $this->view($user, $serviceRequest);
    }

    public function comment(User $user, ServiceRequest $serviceRequest): bool
    {
        return $this->view($user, $serviceRequest);
    }

    private function isOwner(User $user, ServiceRequest $serviceRequest): bool
    {
        return $user->isEmployee()
            && (int) $serviceRequest->requester_id === $user->id;
    }

    private function isManagerOf(User $manager, ServiceRequest $serviceRequest): bool
    {
        return User::query()
            ->whereKey($serviceRequest->requester_id)
            ->where('manager_id', $manager->id)
            ->exists();
    }
}