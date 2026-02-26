<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Velocity Login Controller
 *
 * Handles authentication for the Velocity Chrome extension OAuth flow.
 * Uses the standard web guard and redirects back to the OAuth authorize
 * endpoint after successful login.
 */
class VelocityLoginController extends Controller
{
    /**
     * Show the Velocity-branded login form.
     */
    public function create(): View
    {
        return view('velocity.login');
    }

    /**
     * Handle the login request.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Redirect to the intended URL (OAuth authorize endpoint)
            return redirect()->intended('/');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }
}
