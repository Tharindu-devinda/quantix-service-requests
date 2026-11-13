<?php

namespace App\Http\Controllers\Api;

use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest\AssignRequest;
use App\Http\Requests\ServiceRequest\ChangeStatusRequest;
use App\Http\Requests\ServiceRequest\DecisionRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\RequestWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServiceRequestWorkflowController extends Controller
{
    public function __construct(private readonly RequestWorkflowService $workflow) {}

    public function submit(Request $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        Gate::authorize('submit', $serviceRequest);

        return $this->respond($this->workflow->submit($serviceRequest, $request->user()));
    }

    public function decision(DecisionRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $actor = $request->user();
        $comment = $request->validated('comment');

        $updated = match ($request->validated('decision')) {
            'approve' => $this->workflow->approve($serviceRequest, $actor, $comment),
            'reject' => $this->workflow->reject($serviceRequest, $actor, $comment),
            'return' => $this->workflow->returnToRequester($serviceRequest, $actor, $comment),
        };

        return $this->respond($updated);
    }

    public function assign(AssignRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $assignee = User::findOrFail($request->validated('assignee_id'));

        return $this->respond(
            $this->workflow->assign($serviceRequest, $assignee, $request->user(), $request->validated('comment')),
        );
    }

    public function status(ChangeStatusRequest $request, ServiceRequest $serviceRequest): ServiceRequestResource
    {
        $actor = $request->user();
        $comment = $request->validated('comment');

        $updated = match (RequestStatus::from($request->validated('status'))) {
            RequestStatus::InProgress => $this->workflow->start($serviceRequest, $actor, $comment),
            RequestStatus::Completed => $this->workflow->complete($serviceRequest, $actor, $comment),
        };

        return $this->respond($updated);
    }

    private function respond(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        return new ServiceRequestResource($serviceRequest->load(['requester', 'assignee']));
    }
}