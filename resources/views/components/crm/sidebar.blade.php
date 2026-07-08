  <aside id="sidebar" class="crm-sidebar ca-sidebar flex flex-col bg-white border-r border-slate-200/90 shadow-soft">
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

    <nav class="ca-sidebar-nav flex-1" aria-label="Main navigation">
      <!-- Primary modules (8) — add new main items here -->
      <a href="#" class="nav-item active" data-page="dashboard">
        <i data-lucide="layout-dashboard" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Dashboard</span>
      </a>

      <a href="#" class="nav-item" data-page="ca-master">
        <i data-lucide="database" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Master Data</span>
      </a>

      <a href="#" class="nav-item" data-page="assignment">
        <i data-lucide="user-check" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Assignment</span>
      </a>

      <a href="#" class="nav-item" data-page="communication">
        <i data-lucide="mail" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Communication</span>
      </a>

      <a href="#" class="nav-item" data-page="leads" data-nav-employee-only="1">
        <i data-lucide="clipboard-list" class="h-5 w-5 shrink-0"></i>
        <span class="sidebar-label">Lead Management</span>
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
      <button type="button" class="ca-sidebar-footer-btn" id="sidebar-recycle-btn" aria-label="Recycle bin" data-crm-tip="Recycle bin">
        <i data-lucide="trash-2" class="h-5 w-5"></i>
      </button>
      <button type="button" class="ca-sidebar-footer-btn" id="settings-btn" aria-label="Settings" data-crm-tip="Settings" data-page="settings">
        <i data-lucide="settings" class="h-5 w-5"></i>
      </button>
      <button type="button" class="ca-sidebar-footer-btn" id="sidebar-security-btn" aria-label="Security" data-crm-tip="Security" data-page="security">
        <i data-lucide="lock" class="h-5 w-5"></i>
      </button>
      <button type="button" class="ca-sidebar-footer-btn" id="logout-btn" aria-label="Logout" data-crm-tip="Logout">
        <i data-lucide="log-out" class="h-5 w-5"></i>
      </button>
    </div>
  </aside>
