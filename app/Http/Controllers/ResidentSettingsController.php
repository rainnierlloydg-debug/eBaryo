<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class ResidentSettingsController extends Controller
{
    public function profile(Request $request)
    {
        $user = $request->user();

        return response()->json(array_merge($user->only([
            'id', 'name', 'address', 'email', 'phone', 'verification_status', 'created_at', 'notification_preferences',
        ]), ['profile_photo_url' => $user->profile_photo_url]));
    }

    public function updateProfile(Request $request)
    {
        abort_if($request->user()->isAdmin(), 403, 'Admin settings only allow password and notification preference changes.');
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($request->user()->id)],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
        if ($request->user()->isAdmin()) {
            $rules['name'] = ['required', 'string', 'max:150'];
        }
        $data = $request->validate($rules);
        $user = $request->user();
        $oldPhotoPath = $user->profile_photo_path;
        $photo = $data['photo'] ?? null;
        unset($data['photo']);

        if ($photo) {
            $data['profile_photo_path'] = $photo->store('profile-photos', 'local');
        }

        $user->update($data);

        if ($photo && $oldPhotoPath) {
            Storage::disk('local')->delete($oldPhotoPath);
        }

        return $this->profile($request);
    }

    public function profilePhoto(Request $request)
    {
        $path = $request->user()->profile_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $disk = Storage::disk('local');
        $contentType = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return response($disk->get($path), 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
        $data += $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated.']);
    }

    public function updatePreferences(Request $request)
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.email' => ['sometimes', 'boolean'],
            'preferences.in_app' => ['sometimes', 'boolean'],
        ]);
        $preferences = array_merge($request->user()->notification_preferences ?? [], $data['preferences']);
        $request->user()->update(['notification_preferences' => $preferences]);

        return response()->json(['preferences' => $request->user()->fresh()->notification_preferences]);
    }
}
