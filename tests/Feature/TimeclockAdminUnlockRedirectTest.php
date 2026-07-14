<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimeclockAdminUnlockRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_unlock_session_can_open_the_filament_dashboard(): void
    {
        User::create([
            'name' => 'Emergency Admin',
            'username' => 'admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('password123'),
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $this->postJson('/unlock', [
            'method' => 'admin',
            'username' => 'admin',
            'credential' => 'password123',
            'audit_image' => 'data:image/png;base64,'.base64_encode('audit'),
        ])
            ->assertOk()
            ->assertJsonPath('redirect', '/admin');

        Auth::forgetGuards();

        $this->get('/admin')
            ->assertOk();
    }
}
