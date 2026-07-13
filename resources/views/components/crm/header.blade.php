    <header class="crm-header ca-top-header border-b border-slate-200/80 bg-slate-100/90 backdrop-blur-sm">
      <div class="ca-top-header-inner">
        <button id="mobile-menu-btn" class="btn-ghost lg:hidden !p-2 shrink-0" aria-label="Menu">
          <i data-lucide="menu" class="h-5 w-5"></i>
        </button>

        <div class="header-search-wrap" id="search-wrapper">
          <input id="global-search" type="search" autocomplete="off" placeholder="Search..." class="header-search-field" aria-label="Global search" aria-expanded="false" aria-controls="search-results" />
          <button id="calendar-btn" type="button" class="header-search-calendar-btn" aria-label="Calendar" data-crm-tip="Calendar" data-page="demo-calendar">
            <i data-lucide="calendar" class="h-4 w-4"></i>
          </button>
          <div id="search-results" class="search-dropdown hidden" role="listbox"></div>
        </div>

        <div class="header-actions flex items-center gap-3 shrink-0">
          <button id="notification-btn" type="button" class="header-icon-btn header-icon-btn--interactive relative" aria-label="Notifications">
            <i data-lucide="bell" class="h-5 w-5"></i>
            <span id="header-notification-badge" class="header-badge hidden">0</span>
          </button>
          <div class="header-user-wrap" id="header-user-wrap">
            <button type="button" class="header-user-pill" id="profile-menu-btn" aria-expanded="false" aria-haspopup="true" aria-controls="profile-menu">
              <img src={{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }} alt="" class="header-user-pill-logo-img" width="28" height="28" />
              <span class="header-user-pill-text">User</span>
              <span class="header-user-pill-avatar" aria-hidden="true">
                <i data-lucide="chevron-down" class="h-4 w-4"></i>
              </span>
            </button>
            <div id="profile-menu" class="header-profile-menu hidden" role="menu" aria-labelledby="profile-menu-btn">
              <button type="button" class="header-profile-item" role="menuitem" data-profile-action="profile">
                <i data-lucide="user" class="h-4 w-4"></i><span>Profile</span>
              </button>
              <button type="button" class="header-profile-item hidden" role="menuitem" data-profile-action="password" id="profile-change-password">
                <i data-lucide="key" class="h-4 w-4"></i><span id="profile-password-label">Change Password</span>
              </button>
              <button type="button" class="header-profile-item hidden" role="menuitem" data-profile-action="change-login-email" id="profile-change-login-email">
                <i data-lucide="mail" class="h-4 w-4"></i><span>Change Login Email</span>
              </button>
              <button type="button" class="header-profile-item hidden" role="menuitem" data-profile-action="email-configuration" id="profile-email-configuration">
                <i data-lucide="at-sign" class="h-4 w-4"></i><span>Email Configuration</span>
              </button>
              <button type="button" class="header-profile-item hidden" role="menuitem" data-profile-action="reset-employee-password" id="profile-reset-employee-password">
                <i data-lucide="key-round" class="h-4 w-4"></i><span>Reset Employee Password</span>
              </button>
            </div>
          </div>
        </div>

        <!-- Hidden controls (keyboard / FAB still available) -->
        <button id="filter-btn" type="button" class="sr-only" aria-label="Filters" tabindex="-1"></button>
        <button id="quick-actions-btn" type="button" class="sr-only" aria-label="Quick Actions" tabindex="-1"></button>
        <button id="shortcuts-btn" type="button" class="sr-only" aria-label="Shortcuts" tabindex="-1"></button>
        <button id="theme-toggle" type="button" class="sr-only" aria-label="Theme" tabindex="-1"></button>
      </div>
    </header>
