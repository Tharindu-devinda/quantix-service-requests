<?php

namespace App\Notifications;

use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class RequestStatusChanged extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ServiceRequest $serviceRequest,
        public RequestStatus $from,
        public RequestStatus $to,
        public User $actor,
        public ?string $comment = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'request_id' => $this->serviceRequest->id,
            'title' => $this->serviceRequest->title,
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
            'actor' => ['id' => $this->actor->id, 'name' => $this->actor->name],
            'comment' => $this->comment,
            'message' => $this->message(),
        ];
    }

    private function message(): string
    {
        $title = $this->serviceRequest->title;
        $actor = $this->actor->name;

        return match ($this->to) {
            RequestStatus::Submitted => "{$actor} submitted \"{$title}\" for your review.",
            RequestStatus::Approved => "\"{$title}\" was approved by {$actor}.",
            RequestStatus::Rejected => "\"{$title}\" was rejected by {$actor}.",
            RequestStatus::Returned => "\"{$title}\" was returned by {$actor} for changes.",
            RequestStatus::Assigned => "\"{$title}\" was assigned by {$actor}.",
            RequestStatus::InProgress => "Work started on \"{$title}\".",
            RequestStatus::Completed => "\"{$title}\" was completed by {$actor}.",
            RequestStatus::Draft => "\"{$title}\" was created.",
        };
    }
}