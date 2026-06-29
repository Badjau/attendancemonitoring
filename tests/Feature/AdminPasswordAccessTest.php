<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_redirects_to_admin_login_without_employee_unlock(): void
    {
        $this->assertDatabaseCount('employees', 0);

        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }

    public function test_first_admin_account_can_be_registered_without_employees(): void
    {
        $this->assertDatabaseCount('employees', 0);

        $response = $this->post('/admin/register', [
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/admin');
        $this->assertDatabaseHas('users', [
            'username' => 'admin',
            'email' => 'admin@example.test',
            'is_admin' => true,
            'is_it_admin' => true,
        ]);
        $this->assertAuthenticated();
        $this->assertNotNull(session('admin_password_unlocked_by'));
    }

    public function test_admin_password_login_unlocks_admin_without_employees(): void
    {
        User::create([
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $this->assertDatabaseCount('employees', 0);

        $response = $this->post('/admin/login', [
            'username' => 'admin',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/admin');
        $this->assertAuthenticated();
        $this->assertNotNull(session('admin_password_unlocked_by'));
    }

    public function test_admin_registration_locks_after_first_admin_for_locked_visitors(): void
    {
        User::create([
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        Employee::query()->delete();

        $response = $this->get('/admin/register');

        $response->assertRedirect('/admin/login');
    }

    public function test_admin_logout_clears_admin_access(): void
    {
        $user = User::create([
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession([
                'admin_password_unlocked_by' => $user->id,
                'admin_password_unlocked_at' => now()->toDateTimeString(),
                'timeclock_unlocked_by' => 123,
                'timeclock_unlocked_at' => now()->toDateTimeString(),
            ])
            ->post('/admin/password-logout');

        $response->assertRedirect('/unlock?locked=1');
        $this->assertGuest();
        $this->assertNull(session('admin_password_unlocked_by'));
        $this->assertNull(session('timeclock_unlocked_by'));
    }

    public function test_unlock_locked_flag_keeps_user_on_unlock_page(): void
    {
        $response = $this
            ->withSession([
                'timeclock_unlocked_by' => 123,
                'timeclock_unlocked_at' => now()->toDateTimeString(),
            ])
            ->get('/unlock?locked=1');

        $response->assertOk();
        $this->assertNull(session('timeclock_unlocked_by'));
    }
}
