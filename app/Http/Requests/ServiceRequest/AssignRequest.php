<?php

namespace App\Http\Requests\ServiceRequest;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('serviceRequest'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assignee_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Operations->value),
            ],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'assignee_id.exists' => 'The assignee must be an Operations user.',
        ];
    }
}
