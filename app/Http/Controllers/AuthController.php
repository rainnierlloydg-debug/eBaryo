<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ResidentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:75'],
            'last_name' => ['required', 'string', 'max:75'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'barangay' => ['required', 'string', 'max:100'],
            'sitio' => ['required', 'string', 'max:100'],
            'national_id_front' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:8192'],
            'national_id_back' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:8192'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resident = User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => $data['first_name'].' '.$data['last_name'],
            'barangay' => $data['barangay'],
            'sitio' => $data['sitio'],
            'address' => $data['sitio'].', '.$data['barangay'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => 'resident',
            'account_status' => 'pending',
            'verification_status' => 'pending',
            'national_id_path' => $request->file('national_id_front')->store('national-ids'),
            'national_id_back_path' => $request->file('national_id_back')->store('national-ids'),
        ]);

        $admin = User::where('role', 'admin')->first();
        if ($admin) {
            ResidentNotification::create([
                'user_id' => $admin->id,
                'title' => 'Registration awaiting review',
                'message' => "{$resident->name} submitted a resident registration for ID review.",
                'priority' => 'important',
            ]);
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Registration submitted. The Barangay Admin will review your information and National ID. You can sign in after approval.',
        ], 202);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email']]);
        $credentials += $request->validate(['password' => ['required', 'string']]);

        $user = User::where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }

        if ($user->account_status === 'pending') {
            return response()->json(['registration_status' => 'pending', 'message' => 'Your registration is Pending. The Barangay Admin has not approved your account yet. Please wait for the review.', 'errors' => ['email' => ['Your registration is Pending. The Barangay Admin has not approved your account yet. Please wait for the review.']]], 422);
        }
        if ($user->account_status === 'rejected') {
            return response()->json(['registration_status' => 'rejected', 'message' => 'Your registration was Rejected. Please contact the Barangay Admin for details.', 'errors' => ['email' => ['Your registration was Rejected. Please contact the Barangay Admin for details.']]], 422);
        }
        if ($user->account_status !== 'active') {
            return response()->json(['registration_status' => 'deactivated', 'message' => 'This account is deactivated. Contact the Barangay Admin.', 'errors' => ['email' => ['This account is deactivated. Contact the Barangay Admin.']]], 422);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role']), 'redirect' => $user->isAdmin() ? '/admin' : '/dashboard']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['redirect' => '/login']);
    }

}
