<?php

namespace Tests\Feature;

use App\Mail\PasswordResetConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'smtp']);
    }

    public function test_log_and_array_mailers_cannot_claim_to_send_a_confirmation(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(503)
                ->assertJsonPath('message', 'Email delivery is temporarily unavailable. Please try again later.');
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    private function sendLink(User $user): string
    {
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

        $url = Mail::sent(PasswordResetConfirmation::class)->last()->resetUrl;
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        return $params['token'];
    }

    private function resetData(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
    }

    public function test_confirmation_email_contains_a_link_and_opening_it_does_not_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'OldPassword123!']);
        $token = $this->sendLink($user);
        $mail = Mail::sent(PasswordResetConfirmation::class)->last();
        $this->assertSame(url('/reset-password'), strtok($mail->resetUrl, '?'));
        $this->assertStringContainsString('Confirm and change password', $mail->render());
        $this->get($mail->resetUrl)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertOk();
    }

    public function test_confirmation_link_cannot_reset_another_account_or_accept_a_legacy_otp(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $other = User::factory()->create(['password' => 'OldPassword123!']);
        $token = $this->sendLink($user);
        $this->postJson('/api/reset-password', $this->resetData($other, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $data = $this->resetData($user, $token);
        unset($data['token']);
        $data['otp'] = '123456';
        $this->postJson('/api/reset-password', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('OldPassword123!', $other->fresh()->password));
    }

    public function test_email_confirmation_resets_password_and_cannot_be_reused(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'Resident@example.test', 'account_status' => 'active', 'password' => 'OldPassword123!']);
        $token = $this->sendLink($user);
        Mail::assertSent(PasswordResetConfirmation::class, fn ($mail) => $mail->hasTo($user->email));
        $stored = DB::table('password_reset_tokens')->first();
        $this->assertNotSame($token, $stored->token);
        $this->assertTrue(Hash::check($token, $stored->token));
        $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertGuest();
        $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123!'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPassword123!'])->assertOk();
    }

    public function test_expired_incorrect_and_mismatched_tokens_do_not_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'OldPassword123!']);
        $token = $this->sendLink($user);
        $wrong = str_repeat('x', 64);
        $this->postJson('/api/reset-password', $this->resetData($user, $wrong))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/reset-password', array_merge($this->resetData($user, $token), ['password_confirmation' => 'DifferentPassword!']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->travel(10)->minutes();
        $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }

    public function test_resending_invalidates_old_link_and_requests_have_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $this->sendLink($user);
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertUnprocessable();
        $this->travel(61)->seconds();
        $new = $this->sendLink($user);
        // Resending replaces the stored token hash.
        $this->assertTrue(Hash::check($new, DB::table('password_reset_tokens')->first()->token));
        if ($token !== $new) {
            $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertUnprocessable();
        }
        $this->postJson('/api/reset-password', $this->resetData($user, $new))->assertOk();
    }

    public function test_five_failed_attempts_block_even_a_correct_token_and_resending_does_not_clear_limit(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $this->sendLink($user);
        $wrong = str_repeat('x', 64);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/reset-password', $this->resetData($user, $wrong))->assertUnprocessable();
        }
        $this->travel(61)->seconds();
        $token = $this->sendLink($user);
        $this->postJson('/api/reset-password', $this->resetData($user, $token))->assertUnprocessable()->assertJsonPath('errors.token.0', 'Too many attempts. Please wait 10 minutes before trying again.');
    }

    public function test_unknown_email_has_same_response_without_sending_mail(): void
    {
        Mail::fake();
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertOk()
            ->assertJsonPath('message', 'If this email is registered, a confirmation link has been sent. Open it to change your password within 10 minutes.');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_mail_failure_does_not_leave_a_usable_token(): void
    {
        $user = User::factory()->create();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(503);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_pending_resident_can_receive_confirmation_and_reset_without_barangay_approval(): void
    {
        Mail::fake();
        $user = User::factory()->create(['account_status' => 'pending']);
        $this->postJson('/api/reset-password', $this->resetData($user, $this->sendLink($user)))->assertOk();
        Mail::assertSent(PasswordResetConfirmation::class, fn ($mail) => $mail->hasTo($user->email));
        $this->assertSame('pending', $user->fresh()->account_status);
    }
}
