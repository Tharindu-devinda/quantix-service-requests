<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Exceptions\InvalidTransitionException;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestWorkflowService
{
    public function __construct(private readonly RequestNotifier $notifier) {}

    /**
     * Create a new request as a draft, optionally submitting it straight away.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $requester, array $data, bool $submit = false): ServiceRequest
    {
        return DB::transaction(function () use ($requester, $data, $submit) {
            $serviceRequest = $requester->requests()->create($data);

            $this->log($serviceRequest, $requester, 'created', null, RequestStatus::Draft);

            return $submit
                ? $this->submit($serviceRequest, $requester)
                : $serviceRequest;
        });
    }

    /**
     * Edit a draft or returned request and record the change.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidTransitionException
     */
    public function update(ServiceRequest $serviceRequest, User $actor, array $data): ServiceRequest
    {
        return DB::transaction(function () use ($serviceRequest, $actor, $data) {
            $locked = ServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($serviceRequest->getKey());

            if (! $locked->status->isEditable()) {
                throw InvalidTransitionException::notEditable($locked->status);
            }

            $locked->update($data);

            $this->log($locked, $actor, 'updated', $locked->status, $locked->status);

            return $locked;
        });
    }

    public function submit(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::Submitted, $actor, $comment);
    }

    public function approve(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::Approved, $actor, $comment);
    }

    public function reject(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::Rejected, $actor, $comment);
    }

    public function returnToRequester(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::Returned, $actor, $comment);
    }

    public function assign(ServiceRequest $request, User $assignee, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition(
            $request,
            RequestStatus::Assigned,
            $actor,
            $comment,
            ['assigned_to' => $assignee->id],
        );
    }

    public function start(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::InProgress, $actor, $comment);
    }

    public function complete(ServiceRequest $request, User $actor, ?string $comment = null): ServiceRequest
    {
        return $this->transition($request, RequestStatus::Completed, $actor, $comment);
    }

    /**
     * The one place a status ever changes.
     *
     * @param  array<string, mixed>  $attributes  extra columns to set together with the status
     *
     * @throws InvalidTransitionException
     */
    public function transition(
        ServiceRequest $serviceRequest,
        RequestStatus $to,
        User $actor,
        ?string $comment = null,
        array $attributes = [],
    ): ServiceRequest {
        return DB::transaction(function () use ($serviceRequest, $to, $actor, $comment, $attributes) {

            $locked = ServiceRequest::query()
                ->lockForUpdate()
                ->findOrFail($serviceRequest->getKey());

            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidTransitionException::between($from, $to);
            }

            $changes = array_merge($attributes, ['status' => $to]);

            if ($to === RequestStatus::Submitted) {
                $changes['submitted_at'] = now();
            }

            if ($to === RequestStatus::Completed) {
                $changes['completed_at'] = now();
            }

            $locked->forceFill($changes)->save();

            $this->log($locked, $actor, $this->actionFor($to), $from, $to, $comment);

            $this->notifier->statusChanged($locked, $from, $actor, $comment);

            return $locked;
        });
    }

    private function log(
        ServiceRequest $serviceRequest,
        User $actor,
        string $action,
        ?RequestStatus $from,
        RequestStatus $to,
        ?string $comment = null,
    ): void {
        $serviceRequest->activities()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $comment,
        ]);
    }

    private function actionFor(RequestStatus $to): string
    {
        return match ($to) {
            RequestStatus::Submitted => 'submitted',
            RequestStatus::Approved => 'approved',
            RequestStatus::Rejected => 'rejected',
            RequestStatus::Returned => 'returned',
            RequestStatus::Assigned => 'assigned',
            RequestStatus::InProgress => 'started',
            RequestStatus::Completed => 'completed',
            RequestStatus::Draft => 'created',
        };
    }
}
