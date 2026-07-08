<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Reset Password — CA Cloud Desk CRM</title>
  <link rel="stylesheet" href="{{ asset('crm-ui/src/styles.css') }}" />
</head>
<body class="min-h-screen bg-surface antialiased flex items-center justify-center p-4">
  <div class="card p-8 w-full max-w-md shadow-soft-lg">
    <div class="text-center mb-6">
      <img src="{{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }}" alt="CA Cloud Desk" class="mx-auto mb-4" width="180" height="44" />
      <h1 class="text-page-title text-slate-900">Reset password</h1>
      <p class="text-body text-slate-500 mt-1">Choose a new password for your account.</p>
    </div>

    @if ($errors->any())
      <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="{{ route('crm.password.update') }}" class="space-y-4">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}" />
      <div>
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="input-field" value="{{ old('email', $email) }}" required autofocus />
      </div>
      <div>
        <label class="form-label" for="password">New password</label>
        <input id="password" name="password" type="password" class="input-field" required />
      </div>
      <div>
        <label class="form-label" for="password_confirmation">Confirm password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="input-field" required />
      </div>
      <button type="submit" class="btn-primary w-full justify-center">Reset password</button>
    </form>

    <p class="text-caption text-slate-500 mt-6 text-center">
      <a href="{{ route('crm.login') }}" class="text-brand hover:underline">Back to sign in</a>
    </p>
  </div>
</body>
</html>
