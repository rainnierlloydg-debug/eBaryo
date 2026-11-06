<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResidentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_update_a_residents_name_or_address(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'account_status' => 'active',
            'verification_status' => 'verified',
        ]);
        $resident = User::factory()->create([
            'role' => 'resident',
            'name' => 'Original Resident',
            'address' => 'Old address',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($admin, 'web')->patchJson("/api/admin/residents/{$resident->id}", [
            'name' => 'Updated Resident',
            'address' => 'Updated address',
            'email' => $resident->email,
            'phone' => $resident->phone,
        ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('users', [
            'id' => $resident->id,
            'name' => 'Original Resident',
            'address' => 'Old address',
        ]);
    }
    public function test_rejecting_registration_deletes_account_and_private_id_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('national-ids/front.png', 'front');
        \Illuminate\Support\Facades\Storage::disk('local')->put('national-ids/back.png', 'back');
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'pending', 'verification_status' => 'pending', 'national_id_path' => 'national-ids/front.png', 'national_id_back_path' => 'national-ids/back.png']);
        $this->actingAs($admin)->getJson('/api/admin/residents')->assertJsonPath('total', 1);
        $this->postJson("/api/admin/residents/{$resident->id}/reject-registration", ['reason' => 'ID details do not match.'])->assertOk();
        foreach (['', '?search='.urlencode($resident->email), '?verification_status=rejected'] as $query) {
            $this->getJson('/api/admin/residents'.$query)->assertOk()->assertJsonPath('total', 0);
        }
        $this->assertDatabaseMissing('users', ['id' => $resident->id]);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing(['national-ids/front.png', 'national-ids/back.png']);
        $this->postJson('/api/logout')->assertOk();
        $this->postJson('/api/login', ['email' => $resident->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonMissingPath('registration_status')->assertJsonValidationErrors('email');
        $this->assertGuest();
    }
}
