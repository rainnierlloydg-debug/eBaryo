<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_can_upload_and_view_a_profile_photo(): void
    {
        Storage::fake('local');
        $resident = User::factory()->create([
            'role' => 'resident',
            'account_status' => 'active',
            'verification_status' => 'verified',
        ]);

        $response = $this->actingAs($resident)->post('/api/profile', [
            '_method' => 'PATCH',
            'photo' => UploadedFile::fake()->createWithContent(
                'profile.png',
                file_get_contents(base_path('docs/flowcharts/02b_Registration_AI_Verification.png')),
            ),
        ]);

        $response->assertOk();
        $photoPath = $resident->fresh()->profile_photo_path;
        $this->assertNotNull($photoPath);
        $this->assertTrue(Storage::disk('local')->exists($photoPath));
        $this->get('/api/profile-photo')->assertOk();
    }
}
