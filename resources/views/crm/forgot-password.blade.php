<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Forgot Password — CA Cloud Desk CRM</title>
  <link rel="stylesheet" href="{{ asset('crm-ui/src/styles.css') }}" />
</head>
<body class="min-h-screen bg-surface antialiased flex items-center justify-center p-4">
  <div class="card p-8 w-full max-w-md shadow-soft-lg">
    <div class="text-center mb-6">
      <img src="{{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }}" alt="CA Cloud Desk" class="mx-auto mb-4" width="180" height="44" />
      <h1 class="text-page-title text-slate-900">Forgot password</h1>
      <p class="text-body text-slate-500 mt-1">Enter your login email and we will send a reset link.</p>
    </div>

    @if (session('status'))
      <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ session('status') }}
      </div>
    @endif

    @if ($errors->any())
      <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
        {{ $errors->first() }}
      </div>
    @endif

    <form method="POST" action="{{ route('crm.password.email') }}" class="space-y-4">
      @csrf
      <div>
        <label class="form-label" for="email">Email</label>
        <input id="email" name="email" type="email" class="input-field" value="{{ old('email') }}" required autofocus />
      </div>
      <button type="submit" class="btn-primary w-full justify-center">Send reset link</button>
    </form>

    <p class="text-caption text-slate-500 mt-6 text-center">
      <a href="{{ route('crm.login') }}" class="text-brand hover:underline">Back to sign in</a>
    </p>
  </div>
</body>
</html>
