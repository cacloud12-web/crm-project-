<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Sign in — CA Cloud Desk CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('crm-ui/src/styles.css') }}" />
</head>
<body class="min-h-screen bg-surface antialiased flex items-center justify-center p-4">
  <div class="card p-8 w-full max-w-md shadow-soft-lg">
    <div class="text-center mb-6">
      <img src="{{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }}" alt="CA Cloud Desk" class="mx-auto mb-4" width="180" height="44" />
      <h1 class="text-page-title text-slate-900">Sign in</h1>
      <p class="text-body text-slate-500 mt-1">Enterprise CRM access</p>
    </div>

    @if ($errors->any())
      <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="{{ route('crm.login.attempt') }}" class="space-y-4">
      @csrf
      <div>
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="input-field" value="{{ old('email') }}" required autofocus />
      </div>
      <div>
        <label class="form-label" for="password">Password</label>
        <input id="password" name="password" type="password" class="input-field" required />
      </div>
      <label class="flex items-center gap-2 text-body text-slate-600">
        <input type="checkbox" name="remember" value="1" class="rounded border-slate-300" />
        Remember me
      </label>
      <button type="submit" class="btn-primary w-full justify-center">Sign in</button>
    </form>

    <p class="text-caption text-slate-400 mt-6 text-center">Demo: manager@ca.local / password</p>
  </div>
</body>
</html>
