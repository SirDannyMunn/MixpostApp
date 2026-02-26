<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in to Velocity</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
        }

        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 2rem;
        }

        .logo svg {
            width: 48px;
            height: 48px;
        }

        .logo-text {
            font-size: 2rem;
            font-weight: 700;
            color: white;
            letter-spacing: -0.02em;
        }

        .logo-dot {
            color: #22c55e;
        }

        .card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .card-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .card-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .card-subtitle {
            color: #94a3b8;
            font-size: 0.875rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            color: #e2e8f0;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            color: white;
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .form-input::placeholder {
            color: #64748b;
        }

        .form-input:focus {
            outline: none;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
        }

        .form-input.error {
            border-color: #ef4444;
        }

        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.5rem;
        }

        .remember-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #94a3b8;
            font-size: 0.875rem;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            width: 1rem;
            height: 1rem;
            accent-color: #22c55e;
            cursor: pointer;
        }

        .forgot-link {
            color: #22c55e;
            font-size: 0.875rem;
            text-decoration: none;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: #16a34a;
        }

        .submit-btn {
            width: 100%;
            padding: 0.875rem 1.5rem;
            background: #22c55e;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .submit-btn:hover {
            background: #16a34a;
            transform: translateY(-1px);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }

        .divider::before {
            margin-right: 1rem;
        }

        .divider::after {
            margin-left: 1rem;
        }

        .footer-text {
            text-align: center;
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 1.5rem;
        }

        .footer-text a {
            color: #22c55e;
            text-decoration: none;
        }

        .footer-text a:hover {
            text-decoration: underline;
        }

        .extension-note {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .extension-note svg {
            width: 20px;
            height: 20px;
            color: #22c55e;
            flex-shrink: 0;
        }

        .extension-note p {
            color: #94a3b8;
            font-size: 0.8125rem;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="48" height="48" rx="12" fill="#22c55e"/>
                <path d="M14 24L22 32L34 16" stroke="white" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="logo-text">velocity<span class="logo-dot">.</span></span>
        </div>

        <div class="card">
            <div class="card-header">
                <h1 class="card-title">Welcome back</h1>
                <p class="card-subtitle">Sign in to connect your extension</p>
            </div>

            <div class="extension-note">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p>Sign in to authorize the Velocity Chrome extension to access your account.</p>
            </div>

            <form method="POST" action="/login">
                @csrf

                <div class="form-group">
                    <label class="form-label" for="email">Email address</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-input @error('email') error @enderror" 
                        value="{{ old('email') }}"
                        placeholder="you@example.com"
                        required 
                        autofocus
                    >
                    @error('email')
                        <p class="error-message">{{ $message }}</p>
                    @enderror
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-input @error('password') error @enderror" 
                        placeholder="••••••••"
                        required
                    >
                    @error('password')
                        <p class="error-message">{{ $message }}</p>
                    @enderror
                </div>

                <div class="remember-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                        Remember me
                    </label>
                </div>

                <button type="submit" class="submit-btn">Sign in</button>
            </form>

            <p class="footer-text">
                Don't have an account? <a href="https://www.velocity.app" target="_blank">Get started</a>
            </p>
        </div>
    </div>
</body>
</html>
