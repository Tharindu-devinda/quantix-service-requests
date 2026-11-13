<?php

namespace App\Enums;

enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * The single source of truth for the workflow.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected, self::Returned],
            self::Returned => [self::Submitted],
            self::Approved => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::Completed],
            self::Rejected, self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** Employees may edit only in these statuses. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In Progress',
            default => ucfirst($this->value),
        };
    }
}
