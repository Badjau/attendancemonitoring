<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\TimeclockAuthorizedUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimeclockLockFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_to_unlock_for_a_locked_fresh_session(): void
    {
        $this->get('/')
            ->assertRedirect('/unlock');
    }

    public function test_offline_attendance_page_redirects_to_unlock_for_a_locked_session(): void
    {
        $this->get('/offline-attendance')
            ->assertRedirect('/unlock');
    }

    public function test_attendance_endpoints_reject_while_locked(): void
    {
        $this->postJson('/attendance/verify-employee', [])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Unlock the timeclock first.');

        $this->postJson('/attendance/record-time-in', [])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Unlock the timeclock first.');

        $this->postJson('/attendance/fingerprint/options', [])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Unlock the timeclock first.');

        $this->postJson('/attendance/fingerprint/record', [])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Unlock the timeclock first.');
    }

    public function test_admin_dashboard_and_legacy_login_redirect_to_unlock_when_locked(): void
    {
        $this->get('/admin')
            ->assertRedirect('/unlock');

        $this->get('/admin/login')
            ->assertRedirect('/unlock');
    }

    public function test_csrf_token_endpoint_returns_current_session_token(): void
    {
        $this->getJson('/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_home_page_exposes_lock_unlock_link(): void
    {
        $homePage = file_get_contents(resource_path('js/Pages/Home.vue'));

        $this->assertStringContainsString('href="/unlock"', $homePage);
        $this->assertStringContainsString('Lock / Unlock', $homePage);
    }

    public function test_unlock_page_admin_panel_hides_visible_scanner_controls(): void
    {
        $unlockPage = file_get_contents(resource_path('js/Pages/TimeclockUnlock.vue'));

        $this->assertStringContainsString('Admin username or email', $unlockPage);
        $this->assertStringContainsString('Admin password', $unlockPage);
        $this->assertStringContainsString('Open admin dashboard', $unlockPage);
        $this->assertStringContainsString("fetch('/csrf-token'", $unlockPage);
        $this->assertStringContainsString("'X-CSRF-TOKEN': token", $unlockPage);
        $this->assertStringNotContainsString('Password keypad', $unlockPage);
        $this->assertStringNotContainsString('Scan RFID card', $unlockPage);
        $this->assertStringNotContainsString('Scan fingerprint', $unlockPage);
        $this->assertStringNotContainsString('Waiting for RFID scan', $unlockPage);
    }

    public function test_admin_unlock_action_restores_kiosk_access(): void
    {
        $this->createAdminUser();

        $this->postJson('/unlock', [
            'method' => 'admin',
            'username' => 'admin',
            'credential' => 'password123',
            'action' => 'unlock',
            'audit_image' => $this->auditImage(),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Timeclock unlocked.')
            ->assertJsonPath('redirect', route('home'));

        $this->assertNotNull(session('timeclock_unlocked_by'));
        $this->assertNull(session('admin_password_unlocked_by'));

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Home')
                ->etc()
            );
    }

    public function test_admin_dashboard_action_uses_admin_login_session(): void
    {
        $this->createAdminUser();

        $this->postJson('/unlock', [
            'method' => 'admin',
            'username' => 'admin',
            'credential' => 'password123',
            'action' => 'dashboard',
            'audit_image' => $this->auditImage(),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Admin unlocked.')
            ->assertJsonPath('redirect', '/admin');

        $this->assertAuthenticated();
        $this->assertNotNull(session('admin_password_unlocked_by'));
        $this->assertNull(session('timeclock_unlocked_by'));

        $this->get('/admin')
            ->assertOk();
    }

    public function test_admin_dashboard_action_supports_normal_unlock_form_submit(): void
    {
        $this->createAdminUser();

        $this->post('/unlock', [
            'method' => 'admin',
            'username' => 'admin',
            'credential' => 'password123',
            'action' => 'dashboard',
        ])
            ->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertNotNull(session('admin_password_unlocked_by'));
        $this->assertNull(session('timeclock_unlocked_by'));
    }

    public function test_admin_dashboard_action_accepts_legacy_unlock_form_field_names(): void
    {
        $this->createAdminUser();

        $this->post('/unlock', [
            'method' => 'admin',
            'admin-username' => 'admin',
            'admin-password' => 'password123',
            'action' => 'dashboard',
        ])
            ->assertRedirect('/admin');

        $this->assertAuthenticated();
        $this->assertNotNull(session('admin_password_unlocked_by'));
    }

    public function test_unlocker_rfid_credential_only_unlocks_the_kiosk(): void
    {
        $employee = $this->createUnlockerEmployee();
        TimeclockAuthorizedUser::create([
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Linked Admin',
            'username' => 'linked-admin',
            'email' => 'linked-admin@example.test',
            'password' => Hash::make('password123'),
            'employee_id' => $employee->id,
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $this->postJson('/unlock', [
            'method' => 'rfid',
            'credential' => 'RFID-123',
            'action' => 'unlock',
            'audit_image' => $this->auditImage(),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Timeclock unlocked.')
            ->assertJsonPath('redirect', route('home'));

        $this->assertGuest();
        $this->assertNotNull(session('timeclock_unlocked_by'));
        $this->assertNull(session('admin_password_unlocked_by'));
        $this->assertNull(session('admin_unlocked_by'));
    }

    public function test_unlocker_rfid_credential_can_lock_an_unlocked_kiosk(): void
    {
        $employee = $this->createUnlockerEmployee();
        TimeclockAuthorizedUser::create([
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $this
            ->withSession(['timeclock_unlocked_by' => 'admin:1'])
            ->postJson('/unlock', [
                'method' => 'rfid',
                'credential' => 'RFID-123',
                'action' => 'lock',
                'audit_image' => $this->auditImage(),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Timeclock locked.')
            ->assertJsonPath('redirect', route('timeclock.unlock'));

        $this->assertNull(session('timeclock_unlocked_by'));
        $this->assertNull(session('admin_password_unlocked_by'));
        $this->assertNull(session('admin_unlocked_by'));
    }

    private function createAdminUser(): User
    {
        return User::create([
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);
    }

    private function createUnlockerEmployee(): Employee
    {
        return Employee::create([
            'department_id' => null,
            'branch' => 'Apo',
            'employee_id' => 'UNLOCK-001',
            'rfid_uid' => 'RFID-123',
            'first_name' => 'Kiosk',
            'last_name' => 'Unlocker',
            'date_of_birth' => '1990-01-01',
            'position' => 'Supervisor',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);
    }

    private function auditImage(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    }
}
