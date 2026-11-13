<?php

namespace App\Http\Requests\ServiceRequest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('decide', $this->route('serviceRequest'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsComment = in_array($this->input('decision'), ['reject', 'return'], true);

        return [
            'decision' => ['required', Rule::in(['approve', 'reject', 'return'])],
            'comment' => ['nullable', Rule::requiredIf($needsComment), 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'comment.required' => 'A comment is required when rejecting or returning a request.',
        ];
    }
}
