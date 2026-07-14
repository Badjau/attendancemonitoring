<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimeclockUnlockRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('method') !== 'admin') {
            return;
        }

        $this->merge([
            'username' => $this->input('username', $this->input('admin-username')),
            'credential' => $this->input('credential', $this->input('admin-password')),
        ]);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::in(['keypad', 'rfid', 'fingerprint', 'admin'])],
            'username' => ['required_if:method,admin', 'nullable', 'string'],
            'credential' => ['required', 'string'],
            'audit_image' => [
                Rule::requiredIf(fn (): bool => $this->input('method') !== 'admin'),
                'nullable',
                'string',
            ],
            'action' => ['nullable', Rule::in(['lock', 'unlock', 'dashboard'])],
        ];
    }
}
