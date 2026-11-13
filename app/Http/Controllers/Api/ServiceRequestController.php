<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest\IndexServiceRequestRequest;
use App\Http\Requests\ServiceRequest\StoreServiceRequestRequest;
use App\Http\Resources\ServiceRequestResource;
use App\Models\ServiceRequest;
use App\Services\RequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ServiceRequestController extends Controller
{
    /**
     * Display a listing of the resource.
     * @param  IndexServiceRequestRequest  $request
     * @return AnonymousResourceCollection
     */
    public function index(IndexServiceRequestRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ServiceRequest::class);

        $filters = $request->validated();

        $requests = ServiceRequest::query()
            ->visibleTo($request->user())
            ->with(['requester', 'assignee'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ServiceRequestResource::collection($requests);
    }

    /**
     * Store a newly created resource in storage.
     * @param  StoreServiceRequestRequest  $request
     * @return JsonResponse
     */
    public function store(StoreServiceRequestRequest $request, RequestWorkflowService $workflow): JsonResponse
    {
        $serviceRequest = $workflow->create($request->user(), $request->validated());

        return (new ServiceRequestResource($serviceRequest->load('requester')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified resource.
     * @param  ServiceRequest  $serviceRequest
     * @return ServiceRequestResource
     */
    public function show(ServiceRequest $serviceRequest): ServiceRequestResource
    {
        Gate::authorize('view', $serviceRequest);

        $serviceRequest->load([
            'requester',
            'assignee',
            'comments' => fn ($query) => $query->oldest()->with('user'),
            'activities' => fn ($query) => $query->oldest()->with('user'),
        ]);

        return new ServiceRequestResource($serviceRequest);
    }
}
