<?php

namespace App\Http\Requests\EmployeeWebAuthn;

use App\Enums\Attendance\Type;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAttendanceRequest extends FormRequest
{
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
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.authenticatorData' => ['required', 'string'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['sometimes', 'nullable'],
            'type' => ['required', 'string'],
            'clientExtensionResults' => ['sometimes', 'array'],
            'authenticatorAttachment' => ['sometimes', 'nullable', 'string'],
            'attendance_type' => ['sometimes', 'nullable', Rule::in([Type::TimeIn->value, Type::TimeOut->value])],
            'attendance_image' => ['sometimes', 'nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
