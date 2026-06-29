<!DOCTYPE html>
<html lang="en">
<head>
<meta name="csrf-token" content="{{ csrf_token() }}">
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CA Cloud Desk â€” CRM Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ asset('crm-ui/src/styles.css') }}" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: { DEFAULT: '#25b7a7', 50: '#e8f8f6', 100: '#ccf0ec', 400: '#25b7a7', 500: '#1e9688', 600: '#187a6f' },
            surface: { DEFAULT: '#F8FAFC' },
          },
          fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
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
  <link rel="icon" href={{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }} />
</head>
<body class="min-h-screen bg-surface antialiased">

  <!-- Overlay -->
  <div id="overlay"></div>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• SIDEBAR â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
  <aside id="sidebar" class="ca-sidebar fixed left-0 top-0 z-50 flex h-screen flex-col bg-white border-r border-slate-200/90 shadow-soft">
    <!-- Logo header (CA Cloud Desk reference) -->
    <div class="ca-sidebar-brand">
      <button id="sidebar-toggle" type="button" class="ca-sidebar-collapse hidden lg:flex" aria-label="Collapse sidebar">
        <i id="sidebar-toggle-icon" data-lucide="chevrons-left" class="h-4 w-4"></i>
      </button>
      <a href="#" class="ca-sidebar-logo-link" data-page="dashboard" aria-label="CA Cloud Desk home">
        <img src={{ asset('crm-ui/assets/communication/logo-ca-clouddesk.png') }} alt="CA Cloud Desk" class="ca-sidebar-logo" width="200" height="48" />
      </a>
    </div>

    <!-- Search (global search lives in header) -->
    <div class="ca-sidebar-toolbar sidebar-label hidden" aria-hidden="true"></div>

    <nav class="ca-sidebar-nav flex-1 overflow-y-auto scrollbar-thin">
      <!-- Primary modules (8) — add new main items here -->
      <a href="#" class="nav-item active" data-page="dashboard">
        <i data-lucide="layout-dashboard" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Dashboard</span>
      </a>

      <a href="#" class="nav-item" data-page="ca-master">
        <i data-lucide="database" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">CA Master</span>
      </a>

      <a href="#" class="nav-item" data-page="leads">
        <i data-lucide="users" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Leads</span>
      </a>

      <a href="#" class="nav-item" data-page="assignment">
        <i data-lucide="user-check" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Assignment</span>
      </a>

      <a href="#" class="nav-item" data-page="communication">
        <i data-lucide="mail" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Communication</span>
      </a>

      <a href="#" class="nav-item" data-page="followups">
        <i data-lucide="calendar-clock" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Follow-ups</span>
      </a>

      <a href="#" class="nav-item" data-page="reports">
        <i data-lucide="file-text" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Reports</span>
      </a>
    </nav>

    <!-- Teal footer bar (CA Cloud Desk reference) -->
    <div class="ca-sidebar-footer">
      <button type="button" class="ca-sidebar-footer-btn" aria-label="Recycle bin" title="Recycle bin">
        <i data-lucide="trash-2" class="h-5 w-5"></i>
      </button>
      <button type="button" class="ca-sidebar-footer-btn" aria-label="Security lock" title="Security">
        <i data-lucide="lock" class="h-5 w-5"></i>
      </button>
      <button type="button" class="ca-sidebar-footer-btn" aria-label="Logout" title="Logout">
        <i data-lucide="log-out" class="h-5 w-5"></i>
      </button>
    </div>
  </aside>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• MAIN â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
  <div id="main-content" class="min-h-screen">
    <!-- Top Nav -->
    <header class="ca-top-header sticky top-0 z-30 border-b border-slate-200/80 bg-slate-100/90 backdrop-blur-sm">
      <div class="ca-top-header-inner">
        <button id="mobile-menu-btn" class="btn-ghost lg:hidden !p-2 shrink-0" aria-label="Menu">
          <i data-lucide="menu" class="h-5 w-5"></i>
        </button>

        <div class="header-search-wrap" id="search-wrapper">
          <input id="global-search" type="search" autocomplete="off" placeholder="Search..." class="header-search-field" aria-label="Global search" aria-expanded="false" aria-controls="search-results" />
          <div id="search-results" class="search-dropdown hidden" role="listbox"></div>
        </div>

        <div class="header-actions flex items-center gap-3 shrink-0">
          <span id="current-time" class="header-time" aria-live="polite">--:--</span>
          <button id="calendar-btn" type="button" class="header-icon-btn header-icon-btn--interactive" aria-label="Calendar" data-page="followups">
            <i data-lucide="calendar" class="h-5 w-5"></i>
          </button>
          <div class="header-settings-wrap" id="header-settings-wrap">
            <button id="settings-btn" type="button" class="header-icon-btn header-icon-btn--interactive" aria-label="Settings" aria-expanded="false" aria-haspopup="true" aria-controls="settings-menu">
              <i data-lucide="settings" class="header-settings-icon h-5 w-5"></i>
            </button>
            <div id="settings-menu" class="header-settings-menu hidden" role="menu" aria-labelledby="settings-btn">
              <button type="button" class="header-settings-item" role="menuitem" data-page="settings">
                <i data-lucide="settings" class="h-4 w-4"></i>
                <span>General Settings</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="settings">
                <i data-lucide="sliders-horizontal" class="h-4 w-4"></i>
                <span>CRM Settings</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="communication">
                <i data-lucide="mail" class="h-4 w-4"></i>
                <span>Communication</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="security">
                <i data-lucide="shield" class="h-4 w-4"></i>
                <span>Security</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="employees">
                <i data-lucide="users" class="h-4 w-4"></i>
                <span>Users</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="security">
                <i data-lucide="key-round" class="h-4 w-4"></i>
                <span>Permissions</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="db-health">
                <i data-lucide="database-backup" class="h-4 w-4"></i>
                <span>Backup &amp; DB Health</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="activity">
                <i data-lucide="scroll-text" class="h-4 w-4"></i>
                <span>Logs</span>
              </button>
              <button type="button" class="header-settings-item" role="menuitem" data-page="queue">
                <i data-lucide="server" class="h-4 w-4"></i>
                <span>Queue &amp; System</span>
              </button>
            </div>
          </div>
          <button id="notification-btn" type="button" class="header-icon-btn header-icon-btn--interactive relative" aria-label="Notifications">
            <i data-lucide="bell" class="h-5 w-5"></i>
            <span id="header-notification-badge" class="header-badge">12</span>
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

    <main id="page-container" class="p-4 lg:p-6 pb-24 page-enter"></main>
  </div>

  <!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• CENTERED MODALS (CA Cloud Desk) â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->

  <!-- Notifications Modal -->
  <div id="notification-drawer" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="notification-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="notification-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="bell" class="h-5 w-5"></i></span>
          Notifications
          <span id="notification-drawer-count" class="badge-brand ml-1">12 new</span>
        </h3>
        <button class="ca-modal-close" data-close-overlay aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div id="notification-drawer-list" class="ca-modal-body space-y-3"></div>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary flex-1" data-action="mark-all-read">Mark All Read</button>
        <button type="button" class="btn-secondary flex-1" data-nav-page="notifications" data-notification-settings="1">Notification Settings</button>
        <button class="btn-primary flex-1" data-nav-page="notifications">View All</button>
      </div>
    </div>
  </div>


  <div id="filter-drawer" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="filter-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="filter-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="filter" class="h-5 w-5"></i></span>
          Lead Filter Preferences

        </h3>
        <button class="ca-modal-close" data-close-overlay aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-4">
        <div class="grid sm:grid-cols-2 gap-4">
          <div class="sc-location-pair">
            <label class="text-caption font-medium text-slate-600 mb-1.5 block">State</label><select class="input-field" id="filter-state" name="state_id"><option value="">All States</option></select>
            <label class="text-caption font-medium text-slate-600 mb-1.5 block mt-3">City</label><select class="input-field" id="filter-city" name="city_id" disabled><option value="">All Cities</option></select>
          </div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Lead Status</label><select class="input-field"><option>All</option><option>Hot</option><option>Warm</option><option>Pipeline</option><option>Lost</option></select></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Team Size Min</label><input type="number" class="input-field" value="1" min="1" max="999" /></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Team Size Max</label><input type="number" class="input-field" value="50" min="1" max="999" /></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Existing Software</label><select class="input-field"><option>Any</option><option>Tally</option><option>Zoho</option><option>None</option><option>Busy</option></select></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Rating Min</label><select class="input-field"><option>Any</option><option>5 stars</option><option>4+ stars</option><option>3+ stars</option></select></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Newly Established</label><select class="input-field"><option>Any</option><option>Yes</option><option>No</option></select></div>
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Assigned To</label><select class="input-field"><option>All Executives</option><option>Rahul Verma</option><option>Priya Sharma</option><option>Anita Desai</option></select></div>
        </div>

        <div class="filter-time-section">
          <p class="filter-time-heading">
            <i data-lucide="clock" class="h-4 w-4 text-brand"></i>
            Time Preference

          </p>
          <div class="mgr-period-tabs filter-time-tabs" role="tablist" id="filter-time-tabs" aria-label="Lead time preference">
            <button type="button" class="mgr-period-tab active" data-period="any">Any</button>
            <button type="button" class="mgr-period-tab" data-period="today">Today</button>
            <button type="button" class="mgr-period-tab" data-period="week">This Week</button>
            <button type="button" class="mgr-period-tab" data-period="month">This Month</button>
          </div>
          <p class="filter-time-hint" id="filter-time-hint" aria-live="polite">No date filter applied</p>
          <div class="grid sm:grid-cols-2 gap-4 mt-3 filter-date-fields" id="filter-date-fields">
            <div>
              <label class="text-caption font-medium text-slate-600 mb-1.5 block" for="filter-date-from">Created From</label>
              <input type="date" id="filter-date-from" class="input-field" disabled />
            </div>
            <div>
              <label class="text-caption font-medium text-slate-600 mb-1.5 block" for="filter-date-to">Created To</label>
              <input type="date" id="filter-date-to" class="input-field" disabled />
            </div>
          </div>
        </div>

        <div class="pt-1">
          <p class="text-caption font-medium text-slate-600 mb-2">Saved Filters</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="badge-brand saved-filter-btn cursor-pointer">Mumbai · Team 6-15 · Tally · 4+</button>
            <button type="button" class="badge-brand saved-filter-btn cursor-pointer">Pune · New Firms</button>
            <button type="button" class="badge-brand saved-filter-btn cursor-pointer">Bangalore · Hot · Rating 5</button>
          </div>
        </div>
      </div>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" id="filter-reset-btn">Reset</button>
        <button type="button" class="btn-secondary" id="filter-save-btn"><i data-lucide="bookmark" class="h-4 w-4"></i> Save</button>
        <button type="button" class="btn-primary flex-1" id="filter-apply-btn">Apply Filters</button>
      </div>
    </div>
  </div>

  <!-- Quick Actions Modal -->
  <div id="quick-actions-menu" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="quick-actions-title">
    <div class="ca-modal-panel ca-modal-panel-sm">
      <div class="ca-modal-header">
        <h3 id="quick-actions-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="zap" class="h-5 w-5"></i></span>
          Quick Actions
        </h3>
        <button class="ca-modal-close" data-close-overlay aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body ca-modal-actions">
        <button class="ca-action-btn" data-open-modal="add-lead"><i data-lucide="user-plus" class="h-5 w-5 text-brand"></i><span>Add Lead</span></button>
        <button class="ca-action-btn" data-open-modal="followup"><i data-lucide="phone" class="h-5 w-5 text-brand"></i><span>Log Call</span></button>
        <button class="ca-action-btn" data-open-modal="followup"><i data-lucide="calendar" class="h-5 w-5 text-brand"></i><span>Schedule Demo</span></button>
        <button class="ca-action-btn"><i data-lucide="send" class="h-5 w-5 text-brand"></i><span>Send Message</span></button>
        <button class="ca-action-btn"><i data-lucide="upload" class="h-5 w-5 text-brand"></i><span>Bulk Import</span></button>
        <button class="ca-action-btn"><i data-lucide="download" class="h-5 w-5 text-brand"></i><span>Export Report</span></button>
      </div>
    </div>
  </div>

  <!-- Keyboard Shortcuts Modal -->
  <div id="shortcuts-modal" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="shortcuts-title">
    <div class="ca-modal-panel ca-modal-panel-sm">
      <div class="ca-modal-header">
        <h3 id="shortcuts-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="keyboard" class="h-5 w-5"></i></span>
          Keyboard Shortcuts
        </h3>
        <button class="ca-modal-close" data-close-overlay aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-3 text-body">
        <div class="flex justify-between items-center py-1"><span class="text-slate-600">Global Search</span><kbd class="ca-kbd">Ctrl+K</kbd></div>
        <div class="flex justify-between items-center py-1"><span class="text-slate-600">Quick Actions</span><kbd class="ca-kbd">Q</kbd></div>
        <div class="flex justify-between items-center py-1"><span class="text-slate-600">Shortcuts Help</span><kbd class="ca-kbd">?</kbd></div>
        <div class="flex justify-between items-center py-1"><span class="text-slate-600">Close Panel</span><kbd class="ca-kbd">Esc</kbd></div>
        <div class="flex justify-between items-center py-1"><span class="text-slate-600">New Lead</span><kbd class="ca-kbd">N</kbd></div>
      </div>
    </div>
  </div>

  <!-- Record Detail Modal -->
  <div id="detail-drawer" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="detail-drawer-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <div class="min-w-0">
          <p id="detail-drawer-caption" class="text-caption text-brand-600 mb-0.5">Record Details</p>
          <h3 id="detail-drawer-title" class="text-card-heading text-slate-900 truncate">—</h3>
        </div>
        <button id="detail-drawer-close" class="ca-modal-close shrink-0" aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div id="detail-drawer-body" class="ca-modal-body"></div>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary flex-1" id="detail-edit-btn"><i data-lucide="edit-3" class="h-4 w-4"></i> Edit</button>
        <button type="button" class="btn-primary flex-1" id="detail-followup-btn"><i data-lucide="phone" class="h-4 w-4"></i> Follow Up</button>
      </div>
    </div>
  </div>


  <div id="modal-add-lead" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-lead-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="add-lead-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i id="add-lead-title-icon" data-lucide="user-plus" class="h-5 w-5"></i></span>
          <span id="add-lead-title-text">Add Lead</span>

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-lead" class="ca-modal-body" method="POST" action="{{ route('ca-masters.store') }}">
    @csrf
        <input type="hidden" name="ca_id" id="form-lead-ca-id" value="" />
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="form-label">Firm Name</label><input name="firm_name" class="input-field" required placeholder="Sharma & Associates" /></div>
          <div><label class="form-label">CA Name</label><input name="ca_name" class="input-field" required placeholder="R. Sharma" /></div>
          <div><label class="form-label">Mobile <span class="text-slate-400 font-normal text-xs">(optional — add before sending SMS)</span></label><input name="mobile_no" class="input-field" placeholder="9876543210" /></div>
          <div><label class="form-label">Alternate Mobile</label><input name="alternate_mobile_no" class="input-field" placeholder="9123456789" /></div>
          <div><label class="form-label">Email</label><input name="email_id" type="email" class="input-field" required placeholder="ca@firm.com" /></div>
          <div><label class="form-label">GST No.</label><input name="gst_no" class="input-field" placeholder="27AABCS1234L1Z5" /></div>
          <div class="sc-location-pair sm:col-span-2 grid sm:grid-cols-2 gap-4">
            <div><label class="form-label">State</label><select name="state_id" class="input-field" required><option value="">Select state</option></select></div>
            <div><label class="form-label">City</label><select name="city_id" class="input-field" disabled><option value="">Select city</option></select></div>
          </div>
          <div><label class="form-label">Team Size</label><input name="team_size" type="number" class="input-field" value="8" min="1" /></div>
          <div><label class="form-label">Software</label><select name="existing_software" class="input-field"><option>Tally</option><option>Zoho</option><option>Busy</option><option>None</option></select></div>
          <div><label class="form-label">Website</label><input name="website" class="input-field" placeholder="firm.in" /></div>
          <div><label class="form-label">Rating (1–5)</label><select name="rating" class="input-field"><option value="5">5</option><option value="4">4</option><option value="3" selected>3</option><option value="2">2</option><option value="1">1</option></select></div>
          <div><label class="form-label">New Firm?</label><select name="is_newly_established" class="input-field"><option value="no">No</option><option value="yes">Yes</option></select></div>
          <div><label class="form-label">Source</label><select name="source_id" class="input-field"><option>Website</option><option>Referral</option><option>Exhibition</option><option>Cold Call</option></select></div>
          <div><label class="form-label">Status</label><select name="status" class="input-field"><option>New</option><option>Hot</option><option>Warm</option><option>Pipeline</option><option>Demo Scheduled</option><option>Active</option><option>Inactive</option><option>Lost</option></select></div>
          <div><label class="form-label">Assign Executive</label><select name="executive_id" class="input-field" id="form-executive-select"><option value="">Auto assign later</option></select></div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-lead" id="add-lead-submit-btn" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save Lead</button>
      </div>
    </div>
  </div>

  <div id="modal-lead-contact" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="lead-contact-title">
    <div class="ca-modal-panel ca-modal-panel-sm">
      <div class="ca-modal-header">
        <h3 id="lead-contact-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="contact" class="h-5 w-5"></i></span>
          <span>Update Contact — <span id="lead-contact-title-firm" class="text-slate-600 font-normal"></span></span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-lead-contact" class="ca-modal-body space-y-4">
        <input type="hidden" name="ca_id" value="" />
        <div>
          <label class="form-label">Mobile Number</label>
          <input name="mobile_no" class="input-field" required placeholder="9876543210" />
        </div>
        <div>
          <label class="form-label">Alternate Mobile Number</label>
          <input name="alternate_mobile_no" class="input-field" placeholder="Not Available" />
        </div>
        <div>
          <label class="form-label">Email</label>
          <input name="email_id" type="email" class="input-field" placeholder="ca@firm.com" />
        </div>
        <div>
          <label class="form-label">Website</label>
          <input name="website" class="input-field" placeholder="firm.in" />
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-lead-contact" id="lead-contact-submit-btn" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save</button>
      </div>
    </div>
  </div>


  <div id="modal-add-employee" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-employee-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="add-employee-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="user-cog" class="h-5 w-5"></i></span>
          Add Sales Executive

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-employee" class="ca-modal-body space-y-4">
        <div><label class="form-label">Full Name</label><input name="name" class="input-field" required placeholder="Priya Sharma" /></div>
        <div><label class="form-label">Login Email</label><input name="email_id" type="email" class="input-field" required placeholder="priya@firm.local" autocomplete="off" /></div>
        <div><label class="form-label">Mobile</label><input name="mobile_no" class="input-field" required /></div>
        <div class="sc-location-pair">
          <div><label class="form-label">State</label><select name="state_id" class="input-field"><option value="">Select state</option></select></div>
          <div><label class="form-label">City</label><select name="city_id" class="input-field" disabled><option value="">Select city</option></select></div>
        </div>
        <div><label class="form-label">Date of Joining</label><input name="date_of_joining" type="date" class="input-field" /></div>
        <div id="employee-login-fields" class="space-y-4 border-t border-slate-100 pt-4">
          <p class="text-card-heading text-sm">Login Credentials</p>
          <div><label class="form-label">CRM Access Role</label>
            <select name="crm_role" class="input-field" id="employee-crm-role">
              <option value="employee">Sales Executive (Employee)</option>
              <option value="manager">Manager</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="password-field-wrap">
            <label class="form-label">Password</label>
            <div class="password-input-row">
              <input name="password" type="password" class="input-field" minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters" />
              <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
            </div>
          </div>
          <div class="password-field-wrap">
            <label class="form-label">Confirm Password</label>
            <div class="password-input-row">
              <input name="password_confirmation" type="password" class="input-field" minlength="8" autocomplete="new-password" />
              <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
            </div>
          </div>
        </div>
        <p id="employee-login-status-note" class="text-caption text-slate-500 hidden"></p>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-employee" class="btn-primary flex-1">Save Executive</button>
      </div>
    </div>
  </div>

  <!-- Edit Profile Modal -->
  <div id="modal-edit-profile" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="edit-profile-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="edit-profile-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="user" class="h-5 w-5"></i></span>
          Edit Profile
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-edit-profile" class="ca-modal-body space-y-4">
        <div>
          <label class="form-label">Name</label>
          <input name="name" type="text" class="input-field" required autocomplete="name" />
        </div>
        <div>
          <label class="form-label">Email</label>
          <input name="email" type="email" class="input-field" required autocomplete="email" />
        </div>
        <div>
          <label class="form-label">Role</label>
          <p id="profile-edit-role-display" class="text-sm text-slate-600 py-2">—</p>
        </div>
        <div id="profile-field-designation" class="hidden">
          <label class="form-label">Designation</label>
          <input name="designation" type="text" class="input-field" autocomplete="organization-title" />
        </div>
        <div id="profile-field-mobile" class="hidden">
          <label class="form-label">Mobile</label>
          <input name="mobile_no" type="tel" class="input-field" autocomplete="tel" />
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-edit-profile" class="btn-primary flex-1">Save Profile</button>
      </div>
    </div>
  </div>

  <!-- Change Password Modal -->
  <div id="modal-change-password" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="change-password-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="change-password-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="key" class="h-5 w-5"></i></span>
          <span id="change-password-title-text">Change Password</span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-change-password" class="ca-modal-body space-y-4">
        <div class="password-field-wrap">
          <label class="form-label">Current Password</label>
          <div class="password-input-row">
            <input name="current_password" type="password" class="input-field" required autocomplete="current-password" />
            <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
          </div>
        </div>
        <div class="password-field-wrap">
          <label class="form-label">New Password</label>
          <div class="password-input-row">
            <input name="password" type="password" class="input-field" required minlength="8" autocomplete="new-password" />
            <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
          </div>
        </div>
        <div class="password-field-wrap">
          <label class="form-label">Confirm New Password</label>
          <div class="password-input-row">
            <input name="password_confirmation" type="password" class="input-field" required minlength="8" autocomplete="new-password" />
            <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
          </div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-change-password" class="btn-primary flex-1">Update Password</button>
      </div>
    </div>
  </div>

  <!-- Reset Employee Password Modal -->
  <div id="modal-reset-employee-password" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="reset-employee-password-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="reset-employee-password-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="key-round" class="h-5 w-5"></i></span>
          Reset Employee Password
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-reset-employee-password" class="ca-modal-body space-y-4">
        <div>
          <label class="form-label">Employee</label>
          <select name="employee_id" id="reset-password-employee-select" class="input-field" required></select>
        </div>
        <div><label class="form-label">Login Email</label><input type="text" id="reset-password-employee-email" class="input-field" readonly /></div>
        <div class="password-field-wrap">
          <label class="form-label">New Password</label>
          <div class="password-input-row">
            <input name="password" type="password" class="input-field" required minlength="8" autocomplete="new-password" />
            <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
          </div>
        </div>
        <div class="password-field-wrap">
          <label class="form-label">Confirm New Password</label>
          <div class="password-input-row">
            <input name="password_confirmation" type="password" class="input-field" required minlength="8" autocomplete="new-password" />
            <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
          </div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-reset-employee-password" class="btn-primary flex-1">Reset Password</button>
      </div>
    </div>
  </div>


  <div id="modal-add-campaign" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-campaign-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="add-campaign-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="megaphone" class="h-5 w-5"></i></span>
          <span id="add-campaign-title-label">New Campaign</span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-campaign" class="ca-modal-body space-y-4" novalidate>
        <input type="hidden" name="channel" id="form-campaign-channel" value="whatsapp" />
        <div><label class="form-label">Campaign Name</label><input name="name" class="input-field" required placeholder="June Demo Outreach" /></div>
        <div><label class="form-label">Campaign Type</label><select name="campaign_type" class="input-field" id="form-campaign-type" required></select></div>
        <div id="form-campaign-whatsapp-fields" class="space-y-4 hidden">
          <div><label class="form-label">Message Template</label><textarea name="message_template" class="input-field min-h-[110px]" id="form-campaign-message-template" placeholder="Hi @{{name}}, your demo for @{{firm_name}} is confirmed."></textarea></div>
        </div>
        <div id="form-campaign-email-fields" class="space-y-4 hidden">
          <div><label class="form-label">Email Subject</label><input name="subject" class="input-field" id="form-campaign-email-subject" placeholder="Following up on your demo" /></div>
          <div><label class="form-label">Email Body</label><textarea name="body_template" class="input-field min-h-[110px]" id="form-campaign-body-template" placeholder="Dear @{{name}}, we would like to follow up regarding @{{firm_name}}."></textarea></div>
        </div>
        <div id="form-campaign-sms-fields" class="space-y-4 hidden">
          <div><label class="form-label">Message Template</label><textarea class="input-field min-h-[110px]" id="form-campaign-sms-message-template" placeholder="Hello @{{name}},&#10;Welcome to CA Cloud Desk."></textarea><p class="text-caption text-slate-400 mt-1">Variables: @{{name}}, @{{firm_name}}, @{{city}}, @{{state}}, @{{mobile}}</p></div>
          <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="form-label">Preview Message</label><textarea class="input-field min-h-[90px] bg-slate-50" id="form-campaign-sms-preview-message" readonly placeholder="Select a lead and click Preview Message"></textarea></div>
            <div class="space-y-3">
              <div><label class="form-label">Estimated Recipients</label><input class="input-field bg-slate-50" id="form-campaign-sms-estimated-recipients" readonly value="0" /></div>
              <div class="grid grid-cols-2 gap-3">
                <div><label class="form-label">Character Count</label><input class="input-field bg-slate-50" id="form-campaign-sms-char-count" readonly value="0" /></div>
                <div><label class="form-label">SMS Count</label><input class="input-field bg-slate-50" id="form-campaign-sms-sms-count" readonly value="0" /></div>
              </div>
            </div>
          </div>
          <div id="form-campaign-sms-validation" class="hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"></div>
        </div>
        <div id="form-campaign-audience-fields" class="space-y-4 hidden">
          <div><label class="form-label">Audience Mode</label>
            <select name="audience_mode" class="input-field" id="form-campaign-audience-mode">
              <option value="all_leads">All Leads</option>
              <option value="selected_leads">Selected Leads</option>
              <option value="city">City</option>
              <option value="state">State</option>
              <option value="source">Source</option>
              <option value="rating">Rating</option>
              <option value="team_size">Team Size</option>
              <option value="existing_software">Existing Software</option>
            </select>
          </div>
          <div id="form-campaign-audience-selected" class="hidden"><label class="form-label">Select Leads</label><select name="ca_ids[]" class="input-field" id="form-campaign-ca-ids" multiple size="5"></select></div>
          <div id="form-campaign-audience-city" class="hidden sc-location-pair space-y-4">
            <div><label class="form-label">State</label><select name="campaign_state_id" class="input-field" id="form-campaign-state-for-city" data-sc-role="state"><option value="">Select state</option></select></div>
            <div><label class="form-label">City</label><select name="city_id" class="input-field" id="form-campaign-city-id" data-sc-role="city" disabled><option value="">Select city</option></select></div>
          </div>
          <div id="form-campaign-audience-state" class="hidden"><label class="form-label">State</label><select name="state_id" class="input-field" id="form-campaign-state-id" data-sc-standalone-state><option value="">Select state</option></select></div>
          <div id="form-campaign-audience-source" class="hidden"><label class="form-label">Source</label><select name="source_id" class="input-field" id="form-campaign-source-id"></select></div>
          <div id="form-campaign-audience-rating" class="hidden"><label class="form-label">Rating</label><select name="rating" class="input-field" id="form-campaign-rating"><option value="5">5 ★</option><option value="4">4 ★</option><option value="3">3 ★</option><option value="2">2 ★</option><option value="1">1 ★</option></select></div>
          <div id="form-campaign-audience-team-size" class="hidden"><label class="form-label">Team Size</label><input type="number" name="team_size" class="input-field" id="form-campaign-team-size" min="1" placeholder="10" /></div>
          <div id="form-campaign-audience-existing-software" class="hidden"><label class="form-label">Existing Software</label><select name="existing_software" class="input-field" id="form-campaign-existing-software"><option value="Tally">Tally</option><option value="Zoho">Zoho</option><option value="Busy">Busy</option><option value="None">None</option></select></div>
          <div><label class="form-label">Scheduled Date</label><input type="datetime-local" name="scheduled_at" class="input-field" id="form-campaign-scheduled-at" disabled title="Available after live SMS integration" /></div>
        </div>
        <div id="form-campaign-legacy-fields" class="space-y-4 hidden">
          <div><label class="form-label">Template</label><select name="template_id" class="input-field" id="form-campaign-template"><option value="tpl-new">New template</option><option value="tpl-1">tpl-1 — Demo Confirm v2</option><option value="tpl-2">tpl-2 — Demo Reminder</option><option value="tpl-3">tpl-3 — Brochure Share</option></select></div>
          <div><label class="form-label">Audience</label><select name="audience" class="input-field"><option>All Leads</option><option>Hot Leads</option><option>Warm Leads</option><option>New Leads</option><option>Pipeline</option><option>Demo Scheduled</option><option>Negotiation</option><option>Active Clients</option></select></div>
        </div>
      </form>
      <div class="ca-modal-footer" id="add-campaign-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="button" id="btn-sms-preview-message" class="btn-secondary hidden"><i data-lucide="eye" class="h-4 w-4"></i> Preview Message</button>
        <button type="button" id="btn-sms-save-draft" class="btn-secondary hidden"><i data-lucide="file-text" class="h-4 w-4"></i> Save Draft</button>
        <button type="button" id="btn-sms-preview-payload" class="btn-secondary hidden" disabled><i data-lucide="code-2" class="h-4 w-4"></i> Preview Payload</button>
        <button type="button" id="btn-sms-send-disabled" class="btn-primary hidden" disabled title="Available after API credentials are configured">Send SMS</button>
        <button type="button" id="btn-create-campaign" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Create Campaign</button>
      </div>
    </div>
  </div>


  <div id="modal-assign-lead" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="assign-lead-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="assign-lead-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="user-check" class="h-5 w-5"></i></span>
          Assign Lead

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-assign-lead" class="ca-modal-body space-y-4">
        <div><label class="form-label">Select Lead</label><select name="ca_id" class="input-field" id="form-assign-lead-select" required></select></div>
        <div><label class="form-label">Assign To</label><select name="executive_id" class="input-field" id="form-assign-executive" required></select></div>
        <div><label class="form-label">Assignment Type</label><select name="assignment_type" class="input-field"><option>Manual</option><option>Auto</option></select></div>
        <div><label class="form-label">Reason</label><select name="reason" class="input-field"><option value="MANUAL_ASSIGN">Manual Assignment</option><option value="WORKLOAD_BALANCE">Workload Balance</option><option value="HOT_LEAD_AUTO">Hot Lead Auto</option><option value="CITY_MATCH">City Match</option></select></div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-assign-lead" class="btn-primary flex-1">Assign Now</button>
      </div>
    </div>
  </div>

  <!-- Follow-up Modal — FOLLOW_UP_MANAGEMENT -->
  <div id="modal-followup" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="followup-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="followup-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="calendar-clock" class="h-5 w-5"></i></span>
          Schedule Follow-up

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-followup" class="ca-modal-body space-y-4">
        <div><label class="form-label">Lead</label><select name="ca_id" class="input-field" id="form-fu-lead" required></select></div>
        <div><label class="form-label">Follow-up Type</label><select name="followup_type" class="input-field"><option>Call Status</option><option>Demo Scheduled</option><option>Demo Completed</option><option>Details Shared</option><option>Negotiation</option><option>Follow Up Reminder</option></select></div>
        <div><label class="form-label">Remarks</label><textarea name="remarks" class="input-field" rows="2" placeholder="Discussion notes…"></textarea></div>
        <div><label class="form-label">Scheduled Date</label><input name="scheduled_date" type="datetime-local" class="input-field" required /></div>
        <div><label class="form-label">Priority</label><select name="priority" class="input-field"><option>Normal</option><option>Low</option><option>High</option><option>Urgent</option></select></div>
        <div id="followup-reschedule-reason-wrap" class="hidden"><label class="form-label">Reschedule Reason</label><textarea name="reschedule_reason" class="input-field" rows="2" placeholder="Required when changing scheduled date…"></textarea></div>
        <div><label class="form-label">Next Follow-up</label><input name="next_followup_date" type="date" class="input-field" /></div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-followup" class="btn-primary flex-1">Save Follow-up</button>
      </div>
    </div>
  </div>

  <div id="modal-call-outcome" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="call-outcome-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="call-outcome-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="phone-call" class="h-5 w-5"></i></span>
          Log Call Outcome
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-call-outcome" class="ca-modal-body space-y-4">
        <input type="hidden" name="followup_id" id="call-outcome-followup-id" />
        <input type="hidden" name="ca_id" id="call-outcome-ca-id" />
        <div><label class="form-label">Outcome</label>
          <select name="outcome" id="call-outcome-select" class="input-field" required>
            <option value="">Select outcome…</option>
            <option>Interested</option>
            <option>Busy</option>
            <option>No Answer</option>
            <option>Call Later</option>
            <option>Demo Scheduled</option>
            <option>Demo Completed</option>
            <option>Not Interested</option>
          </select>
        </div>
        <div><label class="form-label">Remarks</label><textarea name="remarks" class="input-field" rows="2" placeholder="Call notes…"></textarea></div>
        <div id="call-outcome-schedule-wrap" class="hidden grid sm:grid-cols-2 gap-3">
          <div><label class="form-label">Next Follow-up Date</label><input name="next_followup_date" type="date" class="input-field" /></div>
          <div><label class="form-label">Next Follow-up Time</label><input name="next_followup_time" type="time" class="input-field" value="10:00" /></div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-call-outcome" class="btn-primary flex-1">Save Outcome</button>
      </div>
    </div>
  </div>

  <div id="modal-add-consent" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-consent-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="add-consent-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="fingerprint" class="h-5 w-5"></i></span>
          Add / Update Consent

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-consent" class="ca-modal-body space-y-4">
        <label class="block"><span class="text-caption text-slate-500">Lead</span>
          <select id="form-consent-lead" name="ca_id" class="ca-input w-full" required></select>
        </label>
        <label class="block"><span class="text-caption text-slate-500">Consent Type</span>
          <select name="consent_type" class="ca-input w-full" required>
            <option value="WhatsApp">WhatsApp</option>
            <option value="Email">Email</option>
            <option value="SMS">SMS</option>
          </select>
        </label>
        <label class="block"><span class="text-caption text-slate-500">Consent Status</span>
          <select name="consent_status" class="ca-input w-full" required>
            <option value="Yes">Yes</option>
            <option value="No">No</option>
          </select>
        </label>
        <label class="block"><span class="text-caption text-slate-500">Consent Date</span>
          <input type="date" name="consent_date" class="ca-input w-full">
        </label>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-consent" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save Consent</button>
      </div>
    </div>
  </div>

  <div id="modal-add-dnd" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-dnd-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="add-dnd-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="ban" class="h-5 w-5"></i></span>
          Add DND Entry

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-dnd" class="ca-modal-body space-y-4">
        <label class="block"><span class="text-caption text-slate-500">Lead</span>
          <select id="form-dnd-lead" name="ca_id" class="ca-input w-full" required></select>
        </label>
        <label class="block"><span class="text-caption text-slate-500">DND Type</span>
          <select name="dnd_type" class="ca-input w-full" required>
            <option value="WA">WA</option>
            <option value="Email">Email</option>
            <option value="SMS">SMS</option>
            <option value="All">All</option>
          </select>
        </label>
        <label class="block"><span class="text-caption text-slate-500">Reason</span>
          <input type="text" name="reason" class="ca-input w-full" maxlength="500" placeholder="Customer requested opt-out">
        </label>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-dnd" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Add DND</button>
      </div>
    </div>
  </div>

  <div id="modal-master-record" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="master-record-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="master-record-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="layers" class="h-5 w-5"></i></span>
          Master Record
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-master-record" class="ca-modal-body space-y-4">
        <input type="hidden" name="entity" id="master-record-entity">
        <input type="hidden" name="record_id" id="master-record-id">
        <div id="master-field-state_name" class="hidden"><label class="form-label">State Name</label><input name="state_name" class="input-field"></div>
        <div id="master-field-city_name" class="hidden"><label class="form-label">City Name</label><input name="city_name" class="input-field"></div>
        <div id="master-field-state_id" class="hidden"><label class="form-label">State</label><select name="state_id" class="input-field" data-sc-standalone-state></select></div>
        <div id="master-field-source_name" class="hidden"><label class="form-label">Source Name</label><input name="source_name" class="input-field"></div>
        <div id="master-field-team_size_min" class="hidden"><label class="form-label">Team Size Min</label><input name="team_size_min" type="number" min="0" class="input-field"></div>
        <div id="master-field-team_size_max" class="hidden"><label class="form-label">Team Size Max</label><input name="team_size_max" type="number" min="0" class="input-field"></div>
        <div id="master-field-team_size_label" class="hidden"><label class="form-label">Label</label><input name="team_size_label" class="input-field"></div>
        <div id="master-field-role_name" class="hidden"><label class="form-label">Role Name</label><input name="role_name" class="input-field"></div>
        <div id="master-field-description" class="hidden"><label class="form-label">Description</label><input name="description" class="input-field"></div>
      </form>
      <div class="ca-modal-footer">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-master-record" class="btn-primary flex-1">Save</button>
      </div>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container" aria-live="polite"></div>

  <!-- FAB Trigger (hidden on dashboard) -->
  <div id="fab-wrap" class="fixed bottom-6 right-6 z-30">
    <button id="fab" class="flex h-14 w-14 items-center justify-center rounded-2xl bg-brand text-white shadow-soft-lg transition-all duration-300 hover:bg-brand-500 hover:shadow-glow hover:scale-105" aria-label="Quick actions">
      <i data-lucide="plus" class="h-6 w-6 transition-transform duration-300"></i>
    </button>
  </div>

  <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js"></script>
  <script>window.__CRM_COMM_ASSETS__ = @json(asset('crm-ui/assets/communication/'));</script>
  <script src="{{ asset('crm-ui/src/constants/data.js') }}"></script>
  <script src="{{ asset('crm-ui/src/utils/listing-search.js') }}"></script>
  <script src="{{ asset('crm-ui/src/api/crm.js') }}"></script>
  <script src="{{ asset('crm-ui/src/components/state-city-dropdown.js') }}"></script>
  <script src="{{ asset('crm-ui/src/pages/pages.js') }}"></script>
  @php
    $crmUser = app(\App\Services\Rbac\RbacService::class)->userPayload(auth()->user());
  @endphp
  <script>window.__CRM_USER__ = @json($crmUser);</script>
  <script src="{{ asset('crm-ui/src/services/rbac.js') }}"></script>
  <script>window.__CRM_INITIAL_PAGE__ = @json($spaPage ?? null);</script>
  <script src="{{ asset('crm-ui/src/app.js') }}"></script>
</body>
</html>
