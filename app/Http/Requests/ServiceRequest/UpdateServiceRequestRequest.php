<?php

namespace App\Http\Requests\ServiceRequest;

use App\Enums\RequestCategory;
use App\Enums\RequestPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceRequest'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(RequestCategory::class)],
            'priority' => ['sometimes', Rule::enum(RequestPriority::class)],
        ];
    }
}