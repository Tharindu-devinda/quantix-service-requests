<?php

namespace Database\Factories;

use App\Enums\RequestStatus;
use App\Models\RequestActivity;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequestActivity>
 */
class RequestActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_request_id' => ServiceRequest::factory(),
            'user_id' => User::factory(),
            'action' => 'submitted',
            'from_status' => RequestStatus::Draft,
            'to_status' => RequestStatus::Submitted,
            'comment' => null,
        ];
    }
}
