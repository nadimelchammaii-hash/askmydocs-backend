<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    /**
     * Log in. Attempts are rate-limited by the "login" limiter (see AppServiceProvider).
     */
    public function store(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            // Same message for unknown email and wrong password, so accounts cannot be enumerated.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // New session ID after login prevents session fixation.
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    /**
     * Log out: end the session and rotate the CSRF token.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
