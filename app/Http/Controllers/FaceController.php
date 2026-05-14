<?php

namespace App\Http\Controllers;

use App\Http\Requests\Face\StoreEmployeeRegistrationRequest;
use App\Http\Requests\Face\StoreRegistrationRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FaceController extends Controller
{
    public function index(): Response
    {
        $employees = Employee::with('media')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'profile_url' => $employee->getFirstMediaUrl('employee-profile'),
            ])
            ->filter(fn (array $employee): bool => filled($employee['profile_url']))
            ->values();

        return Inertia::render('Face/Index', [
            'employees' => $employees,
        ]);
    }

    public function register(): Response
    {
        $employees = Employee::with('media')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'profile_url' => $employee->getFirstMediaUrl('employee-profile'),
            ])
            ->values();

        return Inertia::render('Face/Register', [
            'employees' => $employees,
        ]);
    }

    public function storeRegistration(StoreRegistrationRequest $request): RedirectResponse
    {
        $employee = Employee::where('employee_id', $request->employee_id)->firstOrFail();

        $employee->addMediaFromRequest('face-image')
            ->usingName("{$employee->employee_id} face registration")
            ->usingFileName("face_{$employee->employee_id}_".now()->format('YmdHis').'.jpg')
            ->toMediaCollection('employee-profile');

        return redirect()->back()->with('success', 'Face registered successfully.');
    }

    public function storeEmployeeRegistration(StoreEmployeeRegistrationRequest $request, Employee $employee): JsonResponse
    {
        $employee->addMediaFromRequest('face-image')
            ->usingName("{$employee->employee_id} face registration")
            ->usingFileName("face_{$employee->employee_id}_".now()->format('YmdHis').'.jpg')
            ->toMediaCollection('employee-profile');

        return response()->json([
            'message' => 'Face registered successfully.',
            'profile_url' => $employee->fresh()->getFirstMediaUrl('employee-profile'),
        ]);
    }
}
