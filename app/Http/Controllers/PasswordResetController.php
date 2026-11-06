<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetConfirmation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    private function key(string $email): string
    {
        return 'password-reset:'.hash('sha256', $email);
    }

    public function send(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        if (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            return response()->json(['message' => 'Email delivery is temporarily unavailable. Please try again later.'], 503);
        }
        $email = Str::lower(trim($data['email']));
        $key = $this->key($email).':send';
        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw ValidationException::withMessages(['email' => 'Please wait one minute before requesting another confirmation email.']);
        }
        RateLimiter::hit($key, 60);

        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();
        if ($user) {
            $resetToken = Str::random(64);
            try {
                DB::transaction(function () use ($user, $resetToken): void {
                    DB::table('password_reset_tokens')->updateOrInsert(
                        ['email' => $user->email],
                        ['token' => Hash::make($resetToken), 'created_at' => now()]
                    );
                    $url = url('/reset-password').'?'.http_build_query(['email' => $user->email, 'token' => $resetToken]);
                    Mail::to($user->email)->send(new PasswordResetConfirmation($url));
                });
            } catch (\Throwable $exception) {
                report($exception);
                RateLimiter::clear($key);

                return response()->json(['message' => 'Email could not be sent. Please try again later.'], 503);
            }
        }

        return response()->json(['message' => 'If this email is registered, a confirmation link has been sent. Open it to change your password within 10 minutes.']);
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64', 'alpha_num:ascii'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);
        $email = Str::lower(trim($data['email']));
        $key = $this->key($email).':verify';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['token' => 'Too many attempts. Please wait 10 minutes before trying again.']);
        }
        RateLimiter::hit($key, 600);

        DB::transaction(function () use ($email, $data): void {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $token = $user ? DB::table('password_reset_tokens')->where('email', $user->email)->lockForUpdate()->first() : null;
            if (! $token || ! $token->created_at || Carbon::parse($token->created_at)->addMinutes(10)->lte(now()) || ! Hash::check($data['token'], $token->token)) {
                throw ValidationException::withMessages(['token' => 'Invalid or expired confirmation link. Request a new link and try again.']);
            }
            $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });

        return response()->json(['message' => 'Password updated. You can now log in with your new password.']);
    }
}
