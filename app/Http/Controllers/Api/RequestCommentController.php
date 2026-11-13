<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest\StoreCommentRequest;
use App\Http\Resources\RequestCommentResource;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;

class RequestCommentController extends Controller
{
    public function store(StoreCommentRequest $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $comment = $serviceRequest->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
        ]);

        return (new RequestCommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }
}