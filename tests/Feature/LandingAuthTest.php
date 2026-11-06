<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LandingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_every_diagram_field(): void
    {
        $this->postJson('/api/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'first_name', 'last_name', 'email', 'phone', 'barangay', 'sitio',
                'national_id_front', 'national_id_back', 'password',
            ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_rejects_missing_back_image_and_mismatched_passwords(): void
    {
        Storage::fake('local');
        $this->postJson('/api/register', [
            'first_name' => 'Juan',
            'last_name' => 'Cruz',
            'email' => 'juan@example.test',
            'phone' => '09123456789',
            'barangay' => 'Poblacion',
            'sitio' => 'Centro',
            'national_id_front' => UploadedFile::fake()->createWithContent('front.png',
                file_get_contents(base_path('docs/flowcharts/02b_Registration_AI_Verification.png'))),
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass123!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['national_id_back', 'password']);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_or_empty_credentials_do_not_sign_in(): void
    {
        User::factory()->create(['email' => 'resident@example.test', 'account_status' => 'active']);
        foreach ([[], ['email' => 'unknown@example.test', 'password' => 'password'],
            ['email' => 'resident@example.test', 'password' => 'wrong-password']] as $credentials) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertGuest();
        }
    }

    public function test_valid_credentials_sign_in_and_return_the_correct_destination(): void
    {
        foreach (['resident' => '/dashboard', 'admin' => '/admin'] as $role => $destination) {
            $user = User::factory()->create(['role' => $role, 'account_status' => 'active']);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
                ->assertOk()->assertJsonPath('redirect', $destination);
            $this->assertAuthenticatedAs($user);
            $this->postJson('/api/logout')->assertOk();
        }
    }

    public function test_login_validates_email_before_password_and_never_authenticates_invalid_input(): void
    {
        $this->postJson('/api/login', ['email' => '', 'password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingValidationErrors('password');
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => 'invalid-email', 'password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonMissingValidationErrors('password');
        $this->assertGuest();
        $this->postJson('/api/login', ['email' => 'valid@example.test', 'password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('password')->assertJsonMissingValidationErrors('email');
        $this->assertGuest();
    }
    public function test_login_shows_registration_status_only_after_valid_credentials(): void
    {
        foreach (['pending', 'rejected', 'deactivated'] as $status) {
            $user = User::factory()->create(['account_status' => $status]);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
                ->assertUnprocessable()
                ->assertJsonPath('registration_status', $status)
                ->assertJsonValidationErrors('email');
            $this->assertGuest();
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertUnprocessable()
                ->assertJsonMissingPath('registration_status');
            $this->assertGuest();
        }
    }
}
