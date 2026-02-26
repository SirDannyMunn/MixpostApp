<?php

use App\Http\Controllers\Api\V1\ExtensionAuthorizeController;
use App\Http\Controllers\VelocityLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return response()->json(['status' => 'ok', 'service' => 'velocity-api']);
});

/*
|--------------------------------------------------------------------------
| Velocity Login Routes
|--------------------------------------------------------------------------
|
| These routes handle authentication for the Velocity Chrome extension.
| After login, users are redirected back to the OAuth authorize endpoint.
|
*/

Route::get('/login', [VelocityLoginController::class, 'create'])
    ->middleware('guest')
    ->name('velocity.login');

Route::post('/login', [VelocityLoginController::class, 'store'])
    ->middleware('guest')
    ->name('velocity.login.store');

/*
|--------------------------------------------------------------------------
| Extension OAuth Authorize Endpoint
|--------------------------------------------------------------------------
|
| This endpoint handles OAuth2 Authorization Code + PKCE flow for Chrome
| extensions when the user is authenticated via Sanctum (SPA login).
|
| Unlike Passport's default /oauth/authorize which requires web guard,
| this endpoint uses Sanctum authentication and auto-approves trusted
| extension clients.
|
| See config/extension_oauth.php for allowlist configuration.
|
*/

Route::get('/ext/oauth/authorize', [ExtensionAuthorizeController::class, 'handleAuthorize'])
    ->middleware(['web', 'throttle:60,1'])
    ->name('ext.oauth.authorize');
