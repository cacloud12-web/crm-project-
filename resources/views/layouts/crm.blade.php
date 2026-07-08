<!DOCTYPE html>
<html lang="en">
<head>
@include('components.crm.head')
</head>
<body class="crm-layout antialiased">
  <div id="overlay" class="crm-overlay" aria-hidden="true"></div>

  @include('components.crm.sidebar')

  <div id="main-content" class="crm-content">
    @include('components.crm.header')

    <div id="crm-scroll-area" class="crm-scroll-area" tabindex="-1">
      @yield('content')
    </div>
  </div>

  @yield('overlays')

  @include('components.crm.floating-actions')

  @include('components.crm.scripts')
</body>
</html>
