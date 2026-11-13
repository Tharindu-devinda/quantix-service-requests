<?php

namespace App\Http\Requests\ServiceRequest;

use App\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('changeStatus', $this->route('serviceRequest'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                RequestStatus::InProgress->value,
                RequestStatus::Completed->value,
            ])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}