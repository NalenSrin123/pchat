<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver("google")->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $googleUser = Socialite::driver("google")->user();
        $email = Str::lower((string) $googleUser->getEmail());

        abort_if($email === "", 422, "Google did not provide an email address.");

        $user = DB::transaction(function () use ($googleUser, $email) {
            $user = User::where("google_id", $googleUser->getId())->lockForUpdate()->first();

            if ($user) {
                return $user;
            }

            $user = User::where("email", $email)->lockForUpdate()->first();

            if ($user) {
                $user->forceFill([
                    "google_id" => $googleUser->getId(),
                    "email_verified_at" => $user->email_verified_at ?? now(),
                ])->save();

                return $user;
            }

            return User::create([
                "name" => $googleUser->getName() ?: Str::headline(Str::before($email, "@")),
                "username" => $this->usernameFor($email),
                "email" => $email,
                "google_id" => $googleUser->getId(),
                "password" => Str::random(40),
                "email_verified_at" => now(),
            ]);
        });

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route("dashboard", absolute: false));
    }

    private function usernameFor(string $email): string
    {
        $base = Str::of(Str::before($email, "@"))->lower()->replaceMatches("/[^a-z0-9_]/", "")->limit(20, "")->value();
        $base = $base !== "" ? $base : "user";
        $username = $base;
        $suffix = 2;

        while (User::where("username", $username)->exists()) {
            $username = Str::limit($base, 20 - strlen((string) $suffix), "") . $suffix;
            $suffix++;
        }

        return $username;
    }
}
