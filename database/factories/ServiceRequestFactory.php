<?php

namespace Database\Factories;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRequest>
 */
class ServiceRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(RequestCategory::cases()),
            'priority' => fake()->randomElement(RequestPriority::cases()),
            'status' => RequestStatus::Draft,
            'requester_id' => User::factory(),
        ];
    }

    public function status(RequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
