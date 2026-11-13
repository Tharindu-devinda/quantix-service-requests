<?php

namespace Tests\Unit\Workflow;

use App\Enums\RequestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestStatusTest extends TestCase
{
    /**
     * @return array<int, array{0: RequestStatus, 1: RequestStatus}>
     */
    private static function validPairs(): array
    {
        return [
            [RequestStatus::Draft, RequestStatus::Submitted],
            [RequestStatus::Submitted, RequestStatus::Approved],
            [RequestStatus::Submitted, RequestStatus::Rejected],
            [RequestStatus::Submitted, RequestStatus::Returned],
            [RequestStatus::Returned, RequestStatus::Submitted],
            [RequestStatus::Approved, RequestStatus::Assigned],
            [RequestStatus::Assigned, RequestStatus::InProgress],
            [RequestStatus::InProgress, RequestStatus::Completed],
        ];
    }

    public static function validTransitions(): array
    {
        $cases = [];

        foreach (self::validPairs() as [$from, $to]) {
            $cases["{$from->value} -> {$to->value}"] = [$from, $to];
        }

        return $cases;
    }

    public static function invalidTransitions(): array
    {
        $valid = array_map(fn ($pair) => $pair[0]->value.'>'.$pair[1]->value, self::validPairs());
        $cases = [];

        foreach (RequestStatus::cases() as $from) {
            foreach (RequestStatus::cases() as $to) {
                if (! in_array($from->value.'>'.$to->value, $valid, true)) {
                    $cases["{$from->value} -> {$to->value}"] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transitions_are_allowed(RequestStatus $from, RequestStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transitions_are_rejected(RequestStatus $from, RequestStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
    }

    public function test_rejected_and_completed_are_final(): void
    {
        $this->assertTrue(RequestStatus::Rejected->isFinal());
        $this->assertTrue(RequestStatus::Completed->isFinal());
        $this->assertFalse(RequestStatus::Draft->isFinal());
        $this->assertFalse(RequestStatus::InProgress->isFinal());
    }

    public function test_only_draft_and_returned_are_editable(): void
    {
        foreach (RequestStatus::cases() as $status) {
            $expected = in_array($status, [RequestStatus::Draft, RequestStatus::Returned], true);

            $this->assertSame($expected, $status->isEditable(), "Editable mismatch for {$status->value}");
        }
    }
}
