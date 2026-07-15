<meta name="csrf-token" content="{{ csrf_token() }}">
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>@yield('title', 'CA Cloud Desk — CRM Dashboard')</title>
@include('components.crm.asset-version')
<link rel="stylesheet" href="{{ crm_ui_asset('crm-ui/src/components/crm-datetime-picker.css') }}" />
<link rel="stylesheet" href="{{ crm_ui_asset('crm-ui/src/styles.css') }}" />
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    theme: {
      extend: {
        colors: {
          brand: { DEFAULT: '#25b7a7', 50: '#e8f8f6', 100: '#ccf0ec', 400: '#25b7a7', 500: '#1e9688', 600: '#187a6f' },
          surface: { DEFAULT: '#F8FAFC' },
        },
        fontFamily: {
          sans: [
            '-apple-system',
            'BlinkMacSystemFont',
            '"Segoe UI"',
            'Roboto',
            'Oxygen',
            'Ubuntu',
            'Cantarell',
            '"Fira Sans"',
            '"Droid Sans"',
            '"Helvetica Neue"',
            'sans-serif',
          ],
        },
        borderRadius: { card: '16px' },
        boxShadow: {
          soft: '0 1px 3px 0 rgb(0 0 0 / 0.04), 0 4px 12px -2px rgb(0 0 0 / 0.06)',
          'soft-lg': '0 4px 6px -1px rgb(0 0 0 / 0.05), 0 10px 24px -4px rgb(0 0 0 / 0.08)',
          glow: '0 0 0 3px rgba(37, 183, 167, 0.18)',
        },
      },
    },
  };
</script>
<link rel="icon" href="{{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }}" />
<script>
  (function () {
    var isDesktop = window.matchMedia('(min-width: 1024px)').matches;
    var collapsed = isDesktop;
    try {
      ['crm_sidebar_collapsed', 'sidebar-collapsed', 'crm-sidebar-collapsed'].forEach(function (key) {
        localStorage.removeItem(key);
      });
      if (isDesktop) {
        sessionStorage.setItem('crm_sidebar_collapsed', '1');
      }
    } catch (e) { /* ignore storage errors */ }
    document.documentElement.setAttribute('data-sidebar-collapsed', collapsed ? '1' : '0');
  })();
</script>
