<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAccessRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_admin_cannot_access_it_admin_only_pages(): void
    {
        $employee = Employee::create([
            'department_id' => null,
            'branch' => 'Apo',
            'employee_id' => 'ADMIN-001',
            'first_name' => 'Normal',
            'last_name' => 'Admin',
            'date_of_birth' => '1990-01-01',
            'position' => 'Supervisor',
            'role' => Employee::ROLE_ADMIN,
        ]);

        $response = $this
            ->withSession(['admin_unlocked_by' => $employee->id])
            ->get('/admin/timeclock-authorized-users');

        $response->assertForbidden();
    }

    public function test_it_admin_can_pass_it_admin_only_page_gate(): void
    {
        $user = User::create([
            'name' => 'IT Admin',
            'username' => 'itadmin',
            'email' => 'itadmin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $response = $this
            ->withSession(['admin_password_unlocked_by' => $user->id])
            ->get('/admin/timeclock-authorized-users');

        $response->assertOk();
    }

    public function test_normal_admin_allowed_paths_are_limited(): void
    {
        $this->assertTrue(AdminAccess::normalAdminCanAccessPath('admin'));
        $this->assertTrue(AdminAccess::normalAdminCanAccessPath('admin/announcements'));
        $this->assertTrue(AdminAccess::normalAdminCanAccessPath('admin/attendances'));
        $this->assertTrue(AdminAccess::normalAdminCanAccessPath('admin/departments'));
        $this->assertTrue(AdminAccess::normalAdminCanAccessPath('admin/employees'));

        $this->assertFalse(AdminAccess::normalAdminCanAccessPath('admin/timeclock-authorized-users'));
        $this->assertFalse(AdminAccess::normalAdminCanAccessPath('admin/timeclock-unlock-logs'));
        $this->assertFalse(AdminAccess::normalAdminCanAccessPath('admin/activity-logs'));
        $this->assertFalse(AdminAccess::normalAdminCanAccessPath('admin/general-settings-page'));
        $this->assertFalse(AdminAccess::normalAdminCanAccessPath('admin/zones'));
    }
}
