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
}