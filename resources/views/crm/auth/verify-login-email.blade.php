<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $title }} — CA Cloud Desk CRM</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
    .card { max-width: 480px; width: 100%; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); text-align: center; }
    h1 { font-size: 1.35rem; margin: 0 0 12px; }
    p { margin: 0 0 24px; color: #475569; line-height: 1.6; }
    .badge { display: inline-block; padding: 6px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; margin-bottom: 16px; }
    .badge--success { background: #ecfdf5; color: #047857; }
    .badge--error { background: #fef2f2; color: #b91c1c; }
    a.button { display: inline-block; padding: 10px 18px; background: #25b7a7; color: #fff; text-decoration: none; border-radius: 10px; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <span class="badge {{ $success ? 'badge--success' : 'badge--error' }}">{{ $success ? 'Verified' : 'Failed' }}</span>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    <a class="button" href="{{ route('crm.login') }}">Go to Login</a>
  </div>
</body>
</html>
