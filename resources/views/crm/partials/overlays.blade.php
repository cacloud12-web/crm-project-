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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="crm-toolbar-icon-btn" data-action="mark-all-read" title="Mark All Read" aria-label="Mark All Read"><i data-lucide="check-check" class="h-4 w-4"></i></button>
        <button type="button" class="crm-toolbar-icon-btn" data-nav-page="notifications" data-notification-settings="1" title="Notification Settings" aria-label="Notification Settings"><i data-lucide="settings" class="h-4 w-4"></i></button>
        <button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" data-nav-page="notifications" title="View All" aria-label="View All"><i data-lucide="eye" class="h-4 w-4"></i></button>
        </div>
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
          <div><label class="text-caption font-medium text-slate-600 mb-1.5 block">Assigned To</label><select class="input-field"><option>All Employees</option><option>Rahul Verma</option><option>Priya Sharma</option><option>Anita Desai</option></select></div>
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
              <input type="date" id="filter-date-from" class="input-field" data-crm-date-input data-allow-past disabled />
            </div>
            <div>
              <label class="text-caption font-medium text-slate-600 mb-1.5 block" for="filter-date-to">Created To</label>
              <input type="date" id="filter-date-to" class="input-field" data-crm-date-input data-allow-past disabled />
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="crm-toolbar-icon-btn" id="filter-reset-btn" title="Reset" aria-label="Reset"><i data-lucide="rotate-ccw" class="h-4 w-4"></i></button>
        <button type="button" class="crm-toolbar-icon-btn" id="filter-save-btn" title="Save" aria-label="Save"><i data-lucide="bookmark" class="h-4 w-4"></i></button>
        <button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" id="filter-apply-btn" title="Apply Filters" aria-label="Apply Filters"><i data-lucide="filter" class="h-4 w-4"></i></button>
        </div>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="crm-toolbar-icon-btn" id="detail-edit-btn" title="Edit" aria-label="Edit"><i data-lucide="pencil" class="h-4 w-4"></i></button>
        <button type="button" class="crm-toolbar-icon-btn crm-toolbar-icon-btn--primary" id="detail-followup-btn" title="Follow Up" aria-label="Follow Up"><i data-lucide="phone" class="h-4 w-4"></i></button>
        </div>
      </div>
    </div>
  </div>

  <!-- Report Analytics Drawer (full BI dashboard) -->
  <div id="report-analytics-drawer" class="ra-drawer ca-modal" role="dialog" aria-modal="true" aria-labelledby="ra-title" aria-hidden="true">
    <div class="ra-drawer__backdrop" data-ra-close aria-hidden="true"></div>
    <div class="ra-drawer__panel">
      <div id="ra-root" class="ra-drawer__content"></div>
    </div>
  </div>


  <div id="modal-add-lead" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-lead-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="add-lead-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i id="add-lead-title-icon" data-lucide="user-plus" class="h-5 w-5"></i></span>
          <span id="add-lead-title-text">Add Lead</span>
          <span id="add-lead-lock-badge" class="hidden ml-2 text-xs font-medium"></span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-lead" method="POST" action="{{ route('ca-masters.store') }}" data-form-purpose="lead" novalidate>
        @csrf
        <input type="hidden" name="ca_id" id="form-lead-ca-id" value="" />
        <div class="ca-modal-body">
          <div class="grid sm:grid-cols-2 gap-4">
            <div><label class="form-label">Firm Name <span class="text-rose-500">*</span></label><input name="firm_name" class="input-field" required placeholder="Sharma & Associates" autocomplete="organization" /></div>
            <div><label class="form-label">CA Name <span class="text-rose-500">*</span></label><input name="ca_name" class="input-field" required placeholder="R. Sharma" autocomplete="name" /></div>
            <div><label class="form-label">Phone / Mobile</label><input name="mobile_no" id="form-lead-mobile-no" class="input-field" type="tel" inputmode="numeric" placeholder="9876543210" autocomplete="tel" /><p id="form-lead-mobile-hint" class="hidden text-caption text-slate-500 mt-1">Primary mobile cannot be changed once saved.</p><div id="form-lead-duplicate-warning" class="hidden mt-2 rounded-xl border border-red-200 bg-red-50 p-3 text-caption text-red-800"></div></div>
            <div><label class="form-label">Alternate Mobile</label><input name="alternate_mobile_no" class="input-field" type="tel" inputmode="numeric" placeholder="9123456789" autocomplete="tel" /></div>
            <div class="sc-location-pair sm:col-span-2 grid sm:grid-cols-2 gap-4">
              <div><label class="form-label">State <span class="text-rose-500">*</span></label><select name="state_id" class="input-field" required><option value="">Select state</option></select></div>
              <div><label class="form-label">City</label><select name="city_id" class="input-field" disabled><option value="">Select city</option></select></div>
            </div>
            <div><label class="form-label">Email</label><input name="email_id" type="email" class="input-field" placeholder="ca@firm.com" autocomplete="email" /></div>
            <div><label class="form-label">GST No.</label><input name="gst_no" class="input-field" placeholder="27AABCS1234L1Z5" /></div>
            <div><label class="form-label">Team Size</label><input name="team_size" type="number" class="input-field" value="0" min="0" step="1" /></div>
            <div><label class="form-label">Software</label><select name="existing_software" class="input-field"><option value="None" selected>None</option><option value="Tally">Tally</option><option value="Zoho">Zoho</option><option value="Busy">Busy</option><option value="Marg">Marg</option></select></div>
            <div><label class="form-label">Website</label><input name="website" class="input-field" placeholder="firm.in" /></div>
            <div><label class="form-label">Rating (1–5)</label><select name="rating" class="input-field"><option value="5">5</option><option value="4">4</option><option value="3">3</option><option value="2">2</option><option value="1" selected>1</option></select></div>
            <div><label class="form-label">New Firm?</label><select name="is_newly_established" class="input-field"><option value="" selected>—</option><option value="no">No</option><option value="yes">Yes</option></select></div>
            <div><label class="form-label">Source</label><select name="source_id" class="input-field" id="form-lead-source-id"><option value="">Select source</option></select></div>
            <div><label class="form-label">Status</label><select name="status" class="input-field"><option>New</option><option>Hot</option><option>Warm</option><option>Pipeline</option><option>Demo Scheduled</option><option>Active</option><option>Inactive</option><option>Lost</option></select></div>
            <div><label class="form-label">Assign Employee</label><select name="executive_id" class="input-field" id="form-executive-select" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Auto assign later" data-crm-lookup-placeholder="Search employee…"><option value="">Auto assign later</option></select></div>
            <div id="form-lead-google-section" class="hidden sm:col-span-2 border border-slate-200 rounded-xl p-4 bg-slate-50">
              <p class="text-card-heading text-sm mb-3">Saved Google Places Data</p>
              <div class="grid sm:grid-cols-2 gap-3 text-caption" id="form-lead-google-fields"></div>
            </div>
          </div>
        </div>
        <div class="ca-modal-footer">
          <div class="ca-modal-footer-buttons">
            <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
            <button type="button" id="add-lead-submit-btn" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save Lead</button>
          </div>
        </div>
      </form>
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
          <input name="mobile_no" id="form-lead-contact-mobile-no" class="input-field" placeholder="9876543210" />
          <p id="form-lead-contact-mobile-hint" class="hidden text-caption text-slate-500 mt-1">Primary mobile cannot be changed once saved.</p>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-lead-contact" id="lead-contact-submit-btn" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save</button>
        </div>
      </div>
    </div>
  </div>


  <div id="modal-add-employee" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-employee-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="add-employee-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="user-cog" class="h-5 w-5"></i></span>
          Add Employee

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
        <div><label class="form-label">Date of Joining</label><input name="date_of_joining" type="date" class="input-field" data-crm-date-input data-allow-past /></div>
        <div id="employee-login-fields" class="space-y-4 border-t border-slate-100 pt-4">
          <p class="text-card-heading text-sm">Login Credentials</p>
          <div><label class="form-label">CRM Access Role</label>
            <select name="crm_role" class="input-field" id="employee-crm-role">
              <option value="employee">Employee</option>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-employee" class="btn-primary flex-1">Save Employee</button>
        </div>
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
          <input name="email" type="email" class="input-field" required autocomplete="email" id="profile-edit-email-input" />
          <p id="profile-edit-email-hint" class="hidden text-caption text-slate-500 mt-1">Login email can only be changed from Profile → Change Login Email.</p>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-edit-profile" class="btn-primary flex-1">Save Profile</button>
        </div>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-change-password" class="btn-primary flex-1">Update Password</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Change Login Email Modal -->
  <div id="modal-change-login-email" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="change-login-email-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="change-login-email-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="mail" class="h-5 w-5"></i></span>
          Change Login Email
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-change-login-email" class="ca-modal-body space-y-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-slate-800">Account Email</p>
            <span id="login-email-status-badge" class="login-email-status-badge login-email-status-badge--verified hidden">Verified</span>
            <span id="login-email-pending-badge" class="login-email-status-badge login-email-status-badge--pending hidden">Pending Verification</span>
            <span id="login-email-expired-badge" class="login-email-status-badge login-email-status-badge--expired hidden">Expired</span>
            <span id="login-email-failed-badge" class="login-email-status-badge login-email-status-badge--failed hidden">Failed</span>
          </div>
          <div>
            <label class="form-label">Current Login Email</label>
            <input id="login-email-current-display" type="email" class="input-field bg-white" readonly />
          </div>
          <div id="login-email-pending-panel" class="hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            <p class="font-medium">Pending Verification</p>
            <p class="ecfg-status-line mt-1">Verification email sent to: <strong id="login-email-pending-target">—</strong></p>
            <p class="ecfg-status-line">Expires in: <strong id="login-email-pending-expires">24 Hours</strong></p>
            <p id="login-email-pending-text" class="mt-1 text-amber-800"></p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" id="login-email-resend-btn" class="btn-secondary text-sm">Resend Verification Email</button>
              <button type="button" id="login-email-cancel-btn" class="btn-secondary text-sm">Cancel Pending Request</button>
            </div>
          </div>
          <div id="login-email-expired-panel" class="hidden rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
            <p class="font-medium">Previous request expired</p>
            <p id="login-email-expired-text" class="mt-1 text-slate-600"></p>
          </div>
        </div>
        <div id="login-email-change-fields" class="space-y-4">
          <div>
            <label class="form-label">New Email</label>
            <input name="new_email" type="email" class="input-field" required autocomplete="email" placeholder="new.email@company.com" />
          </div>
          <div>
            <label class="form-label">Confirm New Email</label>
            <input name="new_email_confirmation" type="email" class="input-field" required autocomplete="email" placeholder="new.email@company.com" />
          </div>
          <div class="password-field-wrap">
            <label class="form-label">Current Password</label>
            <div class="password-input-row">
              <input name="current_password" type="password" class="input-field" required autocomplete="current-password" />
              <button type="button" class="btn-secondary btn-sm password-toggle-btn" data-password-toggle aria-label="Show password"><i data-lucide="eye" class="h-4 w-4"></i></button>
            </div>
          </div>
          <p class="text-caption text-slate-500">A verification link will be sent to your new email. Your current login email stays active until verification is complete.</p>
        </div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-change-login-email" id="change-login-email-submit-btn" class="btn-primary flex-1">Send Verification Email</button>
        </div>
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
          <select name="employee_id" id="reset-password-employee-select" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Select employee" data-crm-lookup-placeholder="Search employee name or email…" required><option value="">Select employee</option></select>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-reset-employee-password" class="btn-primary flex-1">Reset Password</button>
        </div>
      </div>
    </div>
  </div>


  <div id="modal-add-campaign" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="add-campaign-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="add-campaign-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="megaphone" class="h-5 w-5"></i></span>
          <span id="add-campaign-title-label">New Campaign</span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-add-campaign" class="ca-modal-body space-y-4" novalidate data-form-purpose="campaign">
        <input type="hidden" name="channel" id="form-campaign-channel" value="whatsapp" />
        <div><label class="form-label">Campaign Name</label><input name="campaign_name" id="form-campaign-name" class="input-field" placeholder="June Demo Outreach" autocomplete="off" /></div>
        <div><label class="form-label">Campaign Type</label><select name="campaign_type" class="input-field" id="form-campaign-type" required></select></div>
        <div id="form-campaign-sender-wrap" class="space-y-4">
          <div id="form-campaign-email-sender-field" class="hidden"><label class="form-label">Send From</label><select name="email_config_id" class="input-field" id="form-campaign-email-config-id"><option value="">Default email account</option></select><p class="text-caption text-slate-400 mt-1">Select the SMTP account used for this campaign.</p></div>
          <div id="form-campaign-sms-sender-field" class="hidden"><label class="form-label">SMS Sender ID</label><select class="input-field" id="form-campaign-sms-sender-id" disabled><option value="">Loading…</option></select><p class="text-caption text-slate-400 mt-1">Uses your configured SMS Alert sender ID.</p></div>
          <div id="form-campaign-whatsapp-sender-field" class="hidden"><label class="form-label">WhatsApp Number</label><select class="input-field" id="form-campaign-whatsapp-sender-id" disabled><option value="">Loading…</option></select><p class="text-caption text-slate-400 mt-1">Pending Meta approval numbers are shown but cannot be selected.</p></div>
        </div>
        <div id="form-campaign-whatsapp-fields" class="space-y-4 hidden">
          <div><label class="form-label">WhatsApp Template</label><select class="input-field" id="form-campaign-whatsapp-template-id" required><option value="">Select approved WhatsApp template</option></select></div>
          <div><label class="form-label">Template Body</label><textarea class="input-field min-h-[110px] bg-slate-50" id="form-campaign-whatsapp-message-template" readonly placeholder="Select a WhatsApp template to view its body"></textarea><p class="text-caption text-slate-400 mt-1">Variables use <code>@{{name}}</code>, <code>@{{firm_name}}</code>, <code>@{{city}}</code>, etc. and are replaced with lead data before sending.</p></div>
          <div><label class="form-label">Preview Message</label><textarea class="input-field min-h-[90px] bg-slate-50" id="form-campaign-whatsapp-preview-message" readonly placeholder="Select a template and lead to preview"></textarea><button type="button" class="btn-secondary btn-sm mt-2" id="btn-wa-preview-message"><i data-lucide="eye" class="h-4 w-4"></i> Preview Message</button></div>
          <div id="form-campaign-whatsapp-validation" class="hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"></div>
          <textarea name="message_template" class="hidden" id="form-campaign-message-template"></textarea>
        </div>
        <div id="form-campaign-email-fields" class="space-y-4 hidden">
          <div><label class="form-label">Email Template</label><select class="input-field" id="form-campaign-email-template-id"><option value="">Select email template (optional)</option></select></div>
          <div><label class="form-label">Email Subject</label><input name="subject" class="input-field" id="form-campaign-email-subject" placeholder="Following up on your demo" /></div>
          <div><label class="form-label">Email Body</label><textarea name="body_template" class="input-field min-h-[160px]" id="form-campaign-body-template" placeholder="Dear {CLIENT_NAME}, we would like to follow up regarding {CA_ORGANIZATION_NAME}."></textarea><p class="text-caption text-slate-400 mt-1">Variables: <code>{CLIENT_NAME}</code>, <code>{CA_ORGANIZATION_NAME}</code>, <code>{SENDER_NAME}</code>, <code>{EMAIL}</code>, <code>{PHONE}</code>, <code>{CITY}</code></p></div>
          <div><label class="form-label">Preview</label><textarea class="input-field min-h-[120px] bg-slate-50" id="form-campaign-email-preview" readonly placeholder="Select a template and lead to preview"></textarea><button type="button" class="btn-secondary btn-sm mt-2" id="btn-email-preview-message"><i data-lucide="eye" class="h-4 w-4"></i> Preview Email</button></div>
        </div>
        <div id="form-campaign-sms-fields" class="space-y-4 hidden">
          <div><label class="form-label">DLT Template</label><select class="input-field" id="form-campaign-sms-template-id" required><option value="">Select approved DLT template</option></select></div>
          <div><label class="form-label">Template Body</label><textarea class="input-field min-h-[110px] bg-slate-50" id="form-campaign-sms-message-template" readonly placeholder="Select a DLT template to view its body"></textarea><p class="text-caption text-slate-400 mt-1">DLT variables use <code>{#var#}</code> placeholders and are replaced with lead data before sending.</p></div>
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
          <div id="form-campaign-sms-send-notice" class="hidden rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"></div>
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
          <div id="form-campaign-audience-selected" class="hidden">
            <div class="campaign-lead-picker" id="campaign-lead-picker">
              <div class="campaign-lead-picker__header">
                <label class="form-label campaign-lead-picker__title">Select Leads</label>
                <p class="campaign-lead-picker__selected-count" id="campaign-lead-picker-selected-label">0 Leads Selected</p>
              </div>
              <div class="campaign-lead-picker__stats" id="campaign-lead-picker-stats">
                <span>Total Leads: <strong id="campaign-lead-picker-total">0</strong></span>
                <span class="campaign-lead-picker__stat-sep">·</span>
                <span>Filtered Leads: <strong id="campaign-lead-picker-filtered">0</strong></span>
                <span class="campaign-lead-picker__stat-sep">·</span>
                <span>Selected Leads: <strong id="campaign-lead-picker-selected">0</strong></span>
              </div>
              <div class="campaign-lead-picker__search-wrap">
                <i data-lucide="search" class="campaign-lead-picker__search-icon h-4 w-4"></i>
                <input type="search" class="input-field campaign-lead-picker__search" id="campaign-lead-picker-search" placeholder="Search firm, CA name, mobile, city…" autocomplete="off" />
                <button type="button" class="campaign-lead-picker__search-clear hidden" id="campaign-lead-picker-search-clear" aria-label="Clear search">×</button>
              </div>
              <div class="campaign-lead-picker__bulk" id="campaign-lead-picker-bulk">
                <button type="button" class="btn-secondary btn-sm" id="campaign-lead-picker-select-page">Select Page</button>
                <button type="button" class="btn-secondary btn-sm" id="campaign-lead-picker-select-all">Select All</button>
                <button type="button" class="btn-secondary btn-sm campaign-lead-picker__clear-all" id="campaign-lead-picker-clear">Clear All</button>
              </div>
              <div class="campaign-lead-picker__chips" id="campaign-lead-picker-chips" aria-live="polite"></div>
              <div class="campaign-lead-picker__list-wrap" id="campaign-lead-picker-list-wrap">
                <div class="campaign-lead-picker__list" id="campaign-lead-picker-list" role="listbox" aria-multiselectable="true"></div>
                <div class="campaign-lead-picker__list-footer" id="campaign-lead-picker-list-footer">
                  <button type="button" class="btn-secondary btn-sm hidden" id="campaign-lead-picker-load-more">Load more</button>
                  <span class="text-caption text-slate-400" id="campaign-lead-picker-page-info"></span>
                </div>
              </div>
              <div id="form-campaign-ca-ids-hidden" class="hidden" aria-hidden="true"></div>
            </div>
          </div>
          <div id="form-campaign-audience-city" class="hidden sc-location-pair space-y-4">
            <div><label class="form-label">State</label><select name="campaign_state_id" class="input-field" id="form-campaign-state-for-city" data-sc-role="state"><option value="">Select state</option></select></div>
            <div><label class="form-label">City</label><select name="city_id" class="input-field" id="form-campaign-city-id" data-sc-role="city" disabled><option value="">Select city</option></select></div>
          </div>
          <div id="form-campaign-audience-state" class="hidden"><label class="form-label">State</label><select name="state_id" class="input-field" id="form-campaign-state-id" data-sc-standalone-state><option value="">Select state</option></select></div>
          <div id="form-campaign-audience-source" class="hidden"><label class="form-label">Source</label><select name="source_id" class="input-field" id="form-campaign-source-id"></select></div>
          <div id="form-campaign-audience-rating" class="hidden"><label class="form-label">Rating</label><select name="rating" class="input-field" id="form-campaign-rating"><option value="5">5 ★</option><option value="4">4 ★</option><option value="3">3 ★</option><option value="2">2 ★</option><option value="1">1 ★</option></select></div>
          <div id="form-campaign-audience-team-size" class="hidden"><label class="form-label">Team Size</label><input type="number" name="team_size" class="input-field" id="form-campaign-team-size" min="1" placeholder="10" /></div>
          <div id="form-campaign-audience-existing-software" class="hidden"><label class="form-label">Existing Software</label><select name="existing_software" class="input-field" id="form-campaign-existing-software"><option value="Tally">Tally</option><option value="Zoho">Zoho</option><option value="Busy">Busy</option><option value="None">None</option></select></div>
          <div>
            <label class="form-label" for="form-campaign-scheduled-at">Schedule Date &amp; Time <span class="text-slate-400 font-normal">(optional)</span></label>
            <p class="text-caption text-slate-400 mb-1.5">Leave blank to send immediately. Set a future date and time to schedule the campaign.</p>
            <input type="text" name="scheduled_at" id="form-campaign-scheduled-at" class="input-field" data-crm-datetime-input data-optional data-preview-prefix="Scheduled for" data-placeholder="Select date and time (optional)" autocomplete="off" />
          </div>
        </div>
        <div id="form-campaign-legacy-fields" class="space-y-4 hidden">
          <div><label class="form-label">Template</label><select name="template_id" class="input-field" id="form-campaign-template"><option value="tpl-new">New template</option><option value="tpl-1">tpl-1 — Demo Confirm v2</option><option value="tpl-2">tpl-2 — Demo Reminder</option><option value="tpl-3">tpl-3 — Brochure Share</option></select></div>
          <div><label class="form-label">Audience</label><select name="audience" class="input-field"><option>All Leads</option><option>Hot Leads</option><option>Warm Leads</option><option>New Leads</option><option>Pipeline</option><option>Demo Scheduled</option><option>Negotiation</option><option>Active Clients</option></select></div>
        </div>
      </form>
      <div class="ca-modal-footer" id="add-campaign-footer">
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="button" id="btn-sms-preview-message" class="btn-secondary hidden"><i data-lucide="eye" class="h-4 w-4"></i> Preview Message</button>
        <button type="button" id="btn-sms-save-draft" class="btn-secondary hidden"><i data-lucide="file-text" class="h-4 w-4"></i> Save Draft</button>
        <button type="button" id="btn-sms-preview-payload" class="btn-secondary hidden"><i data-lucide="code-2" class="h-4 w-4"></i> Preview Payload</button>
        <button type="button" id="btn-sms-send" class="btn-primary hidden"><i data-lucide="send" class="h-4 w-4"></i> Send SMS</button>
        <button type="button" id="btn-create-campaign" class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i> Create Campaign</button>
        </div>
      </div>
    </div>
  </div>

  </div>

  <div id="modal-lead-activity-timeline" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="lead-activity-timeline-title">
    <div class="ca-modal-panel ca-modal-panel-sm">
      <div class="ca-modal-header">
        <h3 id="lead-activity-timeline-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="history" class="h-5 w-5"></i></span>
          <span>Activity Timeline — <span id="lead-activity-timeline-firm" class="text-slate-600 font-normal"></span></span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body">
        <div id="lead-activity-timeline-body" class="cam-activity-timeline-list"></div>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary flex-1" data-close-crm-modal>Close</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-lead-team-members" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="lead-team-members-title">
    <div class="ca-modal-panel ca-modal-panel-sm">
      <div class="ca-modal-header">
        <h3 id="lead-team-members-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="users" class="h-5 w-5"></i></span>
          <span>Assigned Team Members — <span id="lead-team-members-firm" class="text-slate-600 font-normal"></span></span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body">
        <div id="lead-team-members-body" class="cam-team-drawer-list space-y-3"></div>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Close</button>
          <button type="button" class="btn-secondary" data-team-members-view-assignment>View Assignment</button>
          <button type="button" class="btn-primary flex-1" data-team-members-reassign>Reassign</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-assign-lead" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="assign-lead-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="assign-lead-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="user-check" class="h-5 w-5"></i></span>
          <span data-assign-title-text>Assign Lead</span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-assign-lead" class="ca-modal-body space-y-4">
        <div id="assign-bulk-summary" class="hidden rounded-lg border border-brand-100 bg-brand-50 px-3 py-2 text-sm text-slate-700"></div>
        <div id="assign-lead-select-wrap"><label class="form-label">Select Lead</label><select name="ca_id" class="input-field" id="form-assign-lead-select" data-crm-entity-lookup="lead" data-crm-lookup-empty-label="Select lead" data-crm-lookup-placeholder="Search firm, CA, mobile, city…" required><option value="">Select lead</option></select></div>
        <div><label class="form-label">Assign Employee</label><select name="executive_id" class="input-field" id="form-assign-executive" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Select employee" data-crm-lookup-placeholder="Search employee…" required><option value="">Select employee</option></select></div>
        <div><label class="form-label">Assignment Type</label><select name="assignment_type" class="input-field"><option>Manual</option><option>Auto</option></select></div>
        <div><label class="form-label">Reason</label><select name="reason" class="input-field"><option value="MANUAL_ASSIGN">Manual Assignment</option><option value="WORKLOAD_BALANCE">Workload Balance</option><option value="HOT_LEAD_AUTO">Hot Lead Auto</option><option value="CITY_MATCH">City Match</option></select></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-assign-lead" class="btn-primary flex-1">Assign Now</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-bulk-delete-leads" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-delete-leads-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="bulk-delete-leads-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="trash-2" class="h-5 w-5"></i></span>
          Delete Selected Leads
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-3">
        <p id="bulk-delete-leads-message" class="text-sm text-slate-700"></p>
        <ul id="bulk-delete-leads-names" class="max-h-40 overflow-y-auto rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-sm text-slate-600 space-y-1"></ul>
        <p class="text-caption text-rose-600">This cannot be undone.</p>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="button" id="bulk-delete-leads-confirm" class="btn-primary flex-1 bg-rose-600 hover:bg-rose-700 border-rose-600">Delete Selected</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Follow-up Modal — FOLLOW_UP_MANAGEMENT -->
  <div id="modal-followup" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="followup-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="followup-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="calendar-clock" class="h-5 w-5"></i></span>
          Schedule Follow-up

        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-followup" class="ca-modal-body space-y-4">
        <input type="hidden" name="ca_id" id="form-followup-ca-id" />
        <div id="followup-lead-picker-wrap">
          <div class="campaign-lead-picker followup-lead-picker" id="followup-lead-picker">
            <div class="campaign-lead-picker__header">
              <label class="form-label campaign-lead-picker__title" for="followup-lead-picker-search">Lead / Firm</label>
              <p class="campaign-lead-picker__selected-count hidden" id="followup-lead-picker-selected-label" aria-hidden="true">0 Leads Selected</p>
            </div>
            <div class="campaign-lead-picker__stats hidden" id="followup-lead-picker-stats" aria-hidden="true">
              <span>Total Leads: <strong id="followup-lead-picker-total">0</strong></span>
              <span class="campaign-lead-picker__stat-sep">·</span>
              <span>Filtered Leads: <strong id="followup-lead-picker-filtered">0</strong></span>
              <span class="campaign-lead-picker__stat-sep">·</span>
              <span>Selected Leads: <strong id="followup-lead-picker-selected">0</strong></span>
            </div>
            <div class="campaign-lead-picker__search-wrap">
              <i data-lucide="search" class="campaign-lead-picker__search-icon h-4 w-4"></i>
              <input type="search" class="input-field campaign-lead-picker__search" id="followup-lead-picker-search" placeholder="Search firm, CA name, mobile…" autocomplete="off" aria-describedby="followup-lead-error" />
              <button type="button" class="campaign-lead-picker__search-clear hidden" id="followup-lead-picker-search-clear" aria-label="Clear search">×</button>
            </div>
            <p id="followup-lead-error" class="ca-field-error hidden" role="alert"></p>
            <div class="campaign-lead-picker__bulk hidden" id="followup-lead-picker-bulk" aria-hidden="true">
              <button type="button" class="btn-secondary btn-sm" id="followup-lead-picker-select-page">Select Page</button>
              <button type="button" class="btn-secondary btn-sm" id="followup-lead-picker-select-all">Select All</button>
              <button type="button" class="btn-secondary btn-sm campaign-lead-picker__clear-all" id="followup-lead-picker-clear">Clear All</button>
            </div>
            <div class="campaign-lead-picker__chips hidden" id="followup-lead-picker-chips" aria-hidden="true"></div>
            <div class="campaign-lead-picker__list-wrap" id="followup-lead-picker-list-wrap">
              <div class="campaign-lead-picker__list" id="followup-lead-picker-list" role="listbox" aria-label="Lead search results"></div>
              <div class="campaign-lead-picker__list-footer" id="followup-lead-picker-list-footer">
                <button type="button" class="btn-secondary btn-sm hidden" id="followup-lead-picker-load-more">Load more</button>
                <span class="text-caption text-slate-400" id="followup-lead-picker-page-info"></span>
              </div>
            </div>
          </div>
        </div>
        <div id="followup-lead-context" class="followup-lead-context card hidden" aria-live="polite">
          <p class="followup-lead-context__title">Lead</p>
          <dl class="followup-lead-context__grid">
            <div class="followup-lead-context__item">
              <dt>Firm Name</dt>
              <dd id="followup-ctx-firm">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>CA Name</dt>
              <dd id="followup-ctx-ca">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>Mobile</dt>
              <dd id="followup-ctx-mobile">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>Current Status</dt>
              <dd id="followup-ctx-status">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>City</dt>
              <dd id="followup-ctx-city">—</dd>
            </div>
            <div class="followup-lead-context__item followup-lead-context__item--full">
              <dt>Assigned Employee</dt>
              <dd id="followup-ctx-employee">—</dd>
            </div>
          </dl>
        </div>
        <div><label class="form-label">Follow-up Type</label><select name="followup_type" class="input-field"><option>Call Status</option><option>Demo Scheduled</option><option>Demo Completed</option><option>Details Shared</option><option>Negotiation</option><option>Follow Up Reminder</option></select></div>
        <div><label class="form-label">Remarks</label><textarea name="remarks" class="input-field" rows="2" placeholder="Discussion notes…"></textarea></div>
        <div>
          <label class="form-label" for="form-followup-scheduled-date">Scheduled Date &amp; Time</label>
          <input name="scheduled_date" type="text" id="form-followup-scheduled-date" class="input-field" data-crm-datetime-input data-preview-prefix="Scheduled for" data-placeholder="Select Date &amp; Time" data-minute-increment="15" data-picker-prefer-above="true" data-hide-calendar-preview="true" autocomplete="off" required />
        </div>
        <div id="followup-demo-fields-wrap" class="hidden space-y-4">
          <div>
            <label class="form-label" for="form-followup-team-size">Team Size</label>
            <input name="team_size" type="number" min="1" step="1" id="form-followup-team-size" class="input-field" placeholder="Auto from lead" />
          </div>
          <div>
            <label class="form-label" for="form-followup-demo-provider">Demo Provider Name</label>
            <input name="demo_provider_name" type="text" id="form-followup-demo-provider" class="input-field" placeholder="Auto from team size" />
          </div>
          <div>
            <label class="form-label" for="form-followup-meeting-link">Meeting Link</label>
            <input name="meeting_link" type="url" id="form-followup-meeting-link" class="input-field" placeholder="https://meet.google.com/…" />
          </div>
        </div>
        <div><label class="form-label">Priority</label><select name="priority" class="input-field"><option>Normal</option><option>Low</option><option>High</option><option>Urgent</option></select></div>
        <div id="followup-reschedule-reason-wrap" class="hidden"><label class="form-label">Reschedule Reason</label><textarea name="reschedule_reason" class="input-field" rows="2" placeholder="Required when changing scheduled date…"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-followup" class="btn-primary flex-1">Save Follow-up</button>
        </div>
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
      <form id="form-call-outcome" class="ca-modal-body space-y-4" novalidate>
        <input type="hidden" name="followup_id" id="call-outcome-followup-id" />
        <input type="hidden" name="ca_id" id="call-outcome-ca-id" />
        <div class="ca-field" data-field="outcome">
          <label class="form-label" for="call-outcome-select">Call Status</label>
          <select name="outcome" id="call-outcome-select" class="input-field">
            <option value="">Select status…</option>
            <option value="Demo Scheduled">Demo Scheduled</option>
            <option value="Follow-up Required">Follow-up Required</option>
            <option value="Interested">Interested</option>
            <option value="Not Interested">Not Interested</option>
            <option value="No Answer">No Answer</option>
            <option value="Busy">Busy</option>
            <option value="Wrong Number">Wrong Number</option>
          </select>
          <p class="ca-field-error hidden" data-error-for="outcome"></p>
        </div>
        <div class="ca-field" data-field="remarks">
          <label class="form-label" for="call-outcome-remarks">Call Note</label>
          <textarea name="remarks" id="call-outcome-remarks" class="input-field" rows="2" placeholder="Call notes…"></textarea>
          <p class="ca-field-error hidden" data-error-for="remarks"></p>
        </div>
        <div id="call-outcome-schedule-wrap" class="hidden">
          <div class="ca-field" data-field="next_followup_date">
            <label class="form-label" for="call-outcome-followup-date">Follow-up Date</label>
            <input name="next_followup_date" id="call-outcome-followup-date" type="date" class="input-field" data-crm-date-input />
            <p class="ca-field-error hidden" data-error-for="next_followup_date"></p>
          </div>
        </div>
        <div id="call-outcome-demo-wrap" class="hidden space-y-4">
          <div class="grid sm:grid-cols-2 gap-4">
            <div class="ca-field" data-field="demo_date">
              <label class="form-label" for="call-outcome-demo-date">Demo Date</label>
              <input name="demo_date" id="call-outcome-demo-date" type="date" class="input-field" data-crm-date-input />
              <p class="ca-field-error hidden" data-error-for="demo_date"></p>
            </div>
            <div class="ca-field" data-field="demo_time">
              <label class="form-label" for="call-outcome-demo-time">Demo Time</label>
              <input name="demo_time" id="call-outcome-demo-time" type="time" class="input-field" value="10:00" />
              <p class="ca-field-error hidden" data-error-for="demo_time"></p>
            </div>
          </div>
          <div class="ca-field" data-field="meeting_link">
            <label class="form-label" for="call-outcome-meeting-link">Meeting / Training Link <span class="text-caption text-slate-400 font-normal">(Optional)</span></label>
            <input name="meeting_link" id="call-outcome-meeting-link" type="url" class="input-field" placeholder="https://…" />
            <p class="ca-field-error hidden" data-error-for="meeting_link"></p>
          </div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-call-outcome" class="btn-primary flex-1">Save Outcome</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-lead-call-log" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="lead-call-log-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="lead-call-log-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="phone-call" class="h-5 w-5"></i></span>
          Call Log
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-lead-call-log" class="ca-modal-body space-y-4" novalidate>
        <input type="hidden" name="ca_id" id="lead-call-log-ca-id" />
        <div id="lead-call-log-context" class="followup-lead-context card hidden" aria-live="polite">
          <p class="followup-lead-context__title">Lead</p>
          <dl class="followup-lead-context__grid">
            <div class="followup-lead-context__item">
              <dt>Firm Name</dt>
              <dd id="lead-call-log-ctx-firm">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>CA Name</dt>
              <dd id="lead-call-log-ctx-ca">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>Mobile</dt>
              <dd id="lead-call-log-ctx-mobile">—</dd>
            </div>
            <div class="followup-lead-context__item">
              <dt>City</dt>
              <dd id="lead-call-log-ctx-city">—</dd>
            </div>
            <div class="followup-lead-context__item followup-lead-context__item--full">
              <dt>Assigned Employee</dt>
              <dd id="lead-call-log-ctx-employee">—</dd>
            </div>
          </dl>
        </div>
        <div class="ca-field" data-field="call_status">
          <label class="form-label" for="lead-call-log-status">Call Status</label>
          <select name="call_status" id="lead-call-log-status" class="input-field">
            <option value="">Select status…</option>
          </select>
          <p class="ca-field-error hidden" data-error-for="call_status"></p>
        </div>
        <div class="ca-field" data-field="call_note">
          <label class="form-label" for="lead-call-log-note">Call Notes</label>
          <textarea name="call_note" id="lead-call-log-note" class="input-field" rows="3" placeholder="Call notes…"></textarea>
          <p class="ca-field-error hidden" data-error-for="call_note"></p>
        </div>
        <div class="ca-field" data-field="called_at">
          <label class="form-label" for="lead-call-log-called-at">Call Date &amp; Time</label>
          <input name="called_at" type="text" id="lead-call-log-called-at" class="input-field" data-crm-datetime-input data-preview-prefix="Called at" data-placeholder="Select date &amp; time" autocomplete="off" required />
          <p class="ca-field-error hidden" data-error-for="called_at"></p>
        </div>
        <div id="lead-call-log-next-wrap" class="hidden">
          <div class="grid sm:grid-cols-2 gap-4">
            <div class="ca-field" data-field="next_followup_date">
              <label class="form-label" for="lead-call-log-next-date">Next Action (Date)</label>
              <input name="next_followup_date" id="lead-call-log-next-date" type="date" class="input-field" data-crm-date-input />
              <p class="ca-field-error hidden" data-error-for="next_followup_date"></p>
            </div>
            <div class="ca-field" data-field="next_followup_time">
              <label class="form-label" for="lead-call-log-next-time">Next Action (Time)</label>
              <input name="next_followup_time" id="lead-call-log-next-time" type="time" class="input-field" value="10:00" />
            </div>
          </div>
        </div>
        <div id="lead-call-log-demo-wrap" class="hidden space-y-4">
          <div class="grid sm:grid-cols-2 gap-4">
            <div class="ca-field" data-field="demo_date">
              <label class="form-label" for="lead-call-log-demo-date">Demo Date</label>
              <input name="demo_date" id="lead-call-log-demo-date" type="date" class="input-field" data-crm-date-input />
              <p class="ca-field-error hidden" data-error-for="demo_date"></p>
            </div>
            <div class="ca-field" data-field="demo_time">
              <label class="form-label" for="lead-call-log-demo-time">Demo Time</label>
              <input name="demo_time" id="lead-call-log-demo-time" type="time" class="input-field" value="10:00" />
              <p class="ca-field-error hidden" data-error-for="demo_time"></p>
            </div>
          </div>
          <div class="ca-field" data-field="meeting_link">
            <label class="form-label" for="lead-call-log-meeting-link">Meeting Link <span class="text-caption text-slate-400 font-normal">(Optional)</span></label>
            <input name="meeting_link" id="lead-call-log-meeting-link" type="url" class="input-field" placeholder="https://…" />
          </div>
        </div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-lead-call-log" class="btn-primary flex-1">Save Call Log</button>
        </div>
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
          <select id="form-consent-lead" name="ca_id" class="ca-input w-full" data-crm-entity-lookup="lead" data-crm-lookup-empty-label="Select lead" data-crm-lookup-placeholder="Search firm, CA, mobile, city…" required><option value="">Select lead</option></select>
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
          <input type="date" name="consent_date" class="ca-input w-full" data-crm-date-input data-allow-past>
        </label>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-consent" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Save Consent</button>
        </div>
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
          <select id="form-dnd-lead" name="ca_id" class="ca-input w-full" data-crm-entity-lookup="lead" data-crm-lookup-empty-label="Select lead" data-crm-lookup-placeholder="Search firm, CA, mobile, city…" required><option value="">Select lead</option></select>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-add-dnd" class="btn-primary flex-1"><i data-lucide="save" class="h-4 w-4"></i> Add DND</button>
        </div>
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
        <div class="ca-modal-footer-buttons">
        <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
        <button type="submit" form="form-master-record" class="btn-primary flex-1">Save</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-master-delete-guard" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="master-delete-guard-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="master-delete-guard-title" class="ca-modal-title">
          <span class="ca-modal-icon master-delete-guard__icon"><i data-lucide="alert-triangle" class="h-5 w-5"></i></span>
          <span id="master-delete-guard-title-text">Cannot Delete Record</span>
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-4">
        <div id="master-delete-guard-loading" class="master-delete-guard__loading hidden">
          <span class="inline-flex items-center gap-2 text-slate-500"><i data-lucide="loader-2" class="h-4 w-4 animate-spin"></i> Checking dependencies…</span>
        </div>
        <div id="master-delete-guard-body" class="space-y-4">
          <p id="master-delete-guard-message" class="text-body text-slate-700"></p>
          <div id="master-delete-guard-usage-wrap" class="hidden">
            <p class="text-caption font-medium text-slate-600 mb-2">This record is currently in use:</p>
            <ul id="master-delete-guard-usage-list" class="master-delete-guard__usage-list"></ul>
          </div>
          <div id="master-delete-guard-recommendation" class="master-delete-guard__recommendation hidden">
            <p class="text-caption text-slate-500">Recommendation: Deactivate this record to prevent it from being selected in new entries while preserving historical data.</p>
          </div>
          <div id="master-delete-guard-view-usage" class="hidden space-y-2">
            <p class="text-caption font-medium text-slate-600">Usage details</p>
            <div id="master-delete-guard-view-usage-list" class="master-delete-guard__view-usage"></div>
          </div>
        </div>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons master-delete-guard__actions">
          <button type="button" class="btn-secondary" data-close-crm-modal id="master-delete-guard-cancel">Cancel</button>
          <button type="button" class="btn-secondary hidden" id="master-delete-guard-view-btn">View Usage</button>
          <button type="button" class="btn-secondary hidden" id="master-delete-guard-reactivate-btn">Reactivate</button>
          <button type="button" class="btn-danger hidden" id="master-delete-guard-deactivate-btn">Deactivate</button>
          <button type="button" class="btn-danger hidden" id="master-delete-guard-confirm-delete-btn">Delete Permanently</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-campaign-detail" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="campaign-detail-title">
    <div class="ca-modal-panel ca-modal-panel-xl">
      <div class="ca-modal-header">
        <h3 id="campaign-detail-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="megaphone" class="h-5 w-5"></i></span>
          Campaign Detail
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-4 max-h-[75vh] overflow-y-auto">
        <div id="campaign-detail-meta" class="flex flex-wrap items-center gap-2"></div>
        <div id="campaign-detail-summary"></div>
        <div><h4 class="font-semibold mb-2">Delivery Stats</h4><div id="campaign-detail-stats"></div></div>
        <div><h4 class="font-semibold mb-2">Recipients</h4><div id="campaign-detail-recipients"></div></div>
        <div><h4 class="font-semibold mb-2">Activity Timeline</h4><div id="campaign-detail-timeline" class="card p-4"></div></div>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Close</button>
          <button type="button" class="btn-secondary" id="campaign-detail-export-btn"><i data-lucide="download" class="h-4 w-4"></i> Export Report</button>
          <button type="button" class="btn-primary" id="campaign-detail-retry-btn"><i data-lucide="refresh-cw" class="h-4 w-4"></i> Retry Failed</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-schedule-demo" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="schedule-demo-title">
    <div class="ca-modal-panel">
      <div class="ca-modal-header">
        <h3 id="schedule-demo-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="presentation" class="h-5 w-5"></i></span>
          Schedule Demo
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-schedule-demo" class="ca-modal-body space-y-4">
        <input type="hidden" name="ca_id" id="schedule-demo-ca-id" />
        <div><label class="form-label">Demo Date/Time</label><input name="demo_at" type="datetime-local" class="input-field" required /></div>
        <div><label class="form-label">Meeting / Training Link</label><input name="meeting_link" type="url" class="input-field" placeholder="https://meet.google.com/…" required /></div>
        <div><label class="form-label">Notes</label><textarea name="notes" class="input-field" rows="2" placeholder="Optional notes…"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-schedule-demo" class="btn-primary flex-1">Schedule Demo</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-demo-result" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="demo-result-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="demo-result-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="clipboard-check" class="h-5 w-5"></i></span>
          Update Demo Result
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-demo-result" class="ca-modal-body space-y-4">
        <input type="hidden" name="demo_schedule_id" id="demo-result-schedule-id" />
        <p id="demo-result-context" class="text-sm text-slate-600 hidden"></p>
        <div><label class="form-label">Demo Result / Remark</label>
          <select name="result" id="demo-result-select" class="input-field" required>
            <option value="">Select result…</option>
            <option value="Interested">Interested</option>
            <option value="Thinking">Thinking</option>
            <option value="Purchasing">Purchasing</option>
            <option value="Purchased">Purchased</option>
            <option value="Not Interested">Not Interested</option>
            <option value="Next Week">Next Week</option>
            <option value="Next Month">Next Month</option>
            <option value="Hold">Hold</option>
          </select>
        </div>
        <div id="demo-result-purchase-wrap" class="hidden space-y-4">
          <p class="text-sm font-medium text-slate-700">Purchase details</p>
          <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            <div><label class="form-label">Month</label><input name="sale_month_preview" id="demo-result-sale-month" type="text" class="input-field bg-slate-50" readonly tabindex="-1" aria-readonly="true" /></div>
            <div><label class="form-label">Point</label><input name="points" id="demo-result-points" type="number" min="0" step="1" class="input-field" /></div>
            <div><label class="form-label">Customer Name</label><input name="customer_name" id="demo-result-customer" type="text" class="input-field" /></div>
            <div><label class="form-label">Firm Name</label><input name="firm_name" id="demo-result-firm" type="text" class="input-field" /></div>
            <div><label class="form-label">Reference</label><input name="reference_name" id="demo-result-reference" type="text" class="input-field" /></div>
            <div><label class="form-label">Mobile Number</label><input name="mobile_no" id="demo-result-mobile" type="text" class="input-field" /></div>
            <div><label class="form-label">City</label><input name="city_name" id="demo-result-city" type="text" class="input-field" /></div>
            <div><label class="form-label">Plan Purchased</label><select name="plan_purchased" id="demo-result-plan" class="input-field"></select></div>
            <div><label class="form-label">Purchase Date</label><input name="purchase_date" id="demo-result-purchase-date" type="date" class="input-field" data-crm-date-input data-allow-past /></div>
            <div><label class="form-label">Cooling Period (days)</label><input name="cooling_period_days" id="demo-result-cooling" type="number" min="0" step="1" class="input-field" /></div>
            <div><label class="form-label">Expiry Date</label><input id="demo-result-expiry" type="text" class="input-field bg-slate-50" readonly tabindex="-1" aria-readonly="true" /></div>
            <div><label class="form-label">Total Amount</label><input name="total_amount" id="demo-result-total" type="number" min="0" step="0.01" class="input-field" /></div>
            <div><label class="form-label">Amount Received</label><input name="amount_received" id="demo-result-received" type="number" min="0" step="0.01" class="input-field" value="0" /></div>
            <div><label class="form-label">Balance Amount</label><input id="demo-result-balance" type="text" class="input-field bg-slate-50" readonly tabindex="-1" aria-readonly="true" /></div>
            <div><label class="form-label">Invoice Number</label><input name="invoice_number" id="demo-result-invoice" type="text" class="input-field" placeholder="Auto-generated if blank" /></div>
            <div><label class="form-label">Payment Status</label><div id="demo-result-payment-status" class="pt-2 text-sm text-slate-600">Pending</div></div>
            <div><label class="form-label">Sales Executive</label><select name="employee_id" id="demo-result-executive" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Unassigned" data-crm-lookup-placeholder="Search executive…"><option value="">Unassigned</option></select></div>
            <div><label class="form-label">Assigned Manager</label><select name="manager_id" id="demo-result-manager" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-empty-label="Unassigned" data-crm-lookup-placeholder="Search manager…"><option value="">Unassigned</option></select></div>
          </div>
        </div>
        <div><label class="form-label">Remark / Notes</label><textarea name="notes" id="demo-result-notes" class="input-field" rows="3" placeholder="e.g. Customer is thinking, will decide next week…"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-demo-result" class="btn-primary flex-1">Save Result</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Yearly Employee Target Modal -->
  <div id="modal-assign-yearly-target" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="assign-yearly-target-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <h3 id="assign-yearly-target-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="target" class="h-5 w-5"></i></span>
          Assign Yearly Target
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-assign-yearly-target" class="ca-modal-body space-y-4" novalidate>
        <input type="hidden" name="target_id" id="assign-yearly-target-id" />
        <input type="hidden" name="employee_id" id="assign-yearly-target-employee-id" value="" />
        <div><label class="form-label">Employee *</label>
          <select id="assign-yearly-target-employee" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-placeholder="Search employee…" aria-required="true"><option value="">Select employee…</option></select>
        </div>
        <div><label class="form-label">Target Year *</label><input name="target_year" id="assign-yearly-target-year-input" type="number" min="2020" max="2100" class="input-field" required /></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="form-label">Leads per day</label><input name="lead_target" id="assign-yearly-target-leads" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Calls per day</label><input name="call_target" id="assign-yearly-target-calls" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Demos per day</label><input name="demo_target" id="assign-yearly-target-demos" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Follow-ups per day</label><input name="followup_target" id="assign-yearly-target-followups" type="number" min="0" step="1" class="input-field" value="0" /></div>
        </div>
        <div id="assign-yearly-target-preview" class="assign-yearly-target-preview hidden">
          <p class="assign-yearly-target-preview__title">Calculated yearly totals</p>
          <dl class="assign-yearly-target-preview__grid">
            <div><dt>Selected Year</dt><dd id="assign-yearly-preview-year">—</dd></div>
            <div><dt>Target Working Days</dt><dd id="assign-yearly-preview-days">—</dd></div>
            <div><dt>Yearly Leads Target</dt><dd id="assign-yearly-preview-leads">—</dd></div>
            <div><dt>Yearly Calls Target</dt><dd id="assign-yearly-preview-calls">—</dd></div>
            <div><dt>Yearly Demos Target</dt><dd id="assign-yearly-preview-demos">—</dd></div>
            <div><dt>Yearly Follow-ups Target</dt><dd id="assign-yearly-preview-followups">—</dd></div>
          </dl>
        </div>
        <div><label class="form-label">Remarks <span class="text-slate-400">(optional)</span></label><textarea name="notes" id="assign-yearly-target-notes" class="input-field" rows="3" placeholder="Optional notes for the employee…"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons flex-wrap">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-assign-yearly-target" class="btn-primary flex-1" id="assign-yearly-target-save">Save Yearly Target</button>
        </div>
      </div>
    </div>
  </div>

  <!-- View Company Holidays Modal -->
  <div id="modal-view-company-holidays" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="view-company-holidays-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <h3 id="view-company-holidays-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="calendar-days" class="h-5 w-5"></i></span> Company Holidays</h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body">
        <p class="text-caption text-slate-500 mb-3">11 fixed holidays applied automatically each year. Sundays are excluded separately.</p>
        <div class="crm-table-container scrollbar-thin"><table class="ca-table w-full"><thead><tr><th>Holiday</th><th>Date</th><th>Notes</th></tr></thead><tbody id="view-company-holidays-table"></tbody></table></div>
      </div>
      <div class="ca-modal-footer"><div class="ca-modal-footer-buttons"><button type="button" class="btn-secondary" data-close-crm-modal>Close</button></div></div>
    </div>
  </div>

  <!-- Edit Holiday Dates Modal -->
  <div id="modal-edit-holiday-dates" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="edit-holiday-dates-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <h3 id="edit-holiday-dates-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="calendar-range" class="h-5 w-5"></i></span> Edit Holiday Dates</h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-edit-holiday-dates" class="ca-modal-body space-y-3">
        <p class="text-caption text-slate-500">Update movable festival dates for the selected year. Duplicate dates are not allowed.</p>
        <div id="edit-holiday-dates-list"></div>
      </form>
      <div class="ca-modal-footer"><div class="ca-modal-footer-buttons"><button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button><button type="submit" form="form-edit-holiday-dates" class="btn-primary">Save Dates</button></div></div>
    </div>
  </div>

  <!-- Employee Leave Modal -->
  <div id="modal-employee-leave" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="employee-leave-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <h3 id="employee-leave-title" class="ca-modal-title"><span class="ca-modal-icon"><i data-lucide="palmtree" class="h-5 w-5"></i></span> Employee Leave</h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body space-y-4">
        <form id="form-employee-leave-request" class="space-y-3 hidden">
          <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="form-label">Leave Date</label><input type="date" id="employee-leave-date" class="input-field" required /></div>
            <div><label class="form-label">Reason</label><input type="text" id="employee-leave-reason" class="input-field" placeholder="Optional" /></div>
          </div>
          <button type="submit" class="btn-primary btn-sm">Request Leave</button>
        </form>
        <div class="crm-table-container scrollbar-thin max-h-72 overflow-auto"><table class="ca-table w-full"><thead><tr><th>Date</th><th>Status</th><th>Reason</th><th></th></tr></thead><tbody id="employee-leave-table"></tbody></table></div>
      </div>
      <div class="ca-modal-footer"><div class="ca-modal-footer-buttons"><button type="button" class="btn-secondary" data-close-crm-modal>Close</button></div></div>
    </div>
  </div>

  <!-- Daily Employee Target Modal (legacy — hidden) -->
  <div id="modal-assign-daily-target" class="ca-modal hidden" role="dialog" aria-modal="true" aria-labelledby="assign-daily-target-title">
    <div class="ca-modal-panel ca-modal-panel-md">
      <div class="ca-modal-header">
        <h3 id="assign-daily-target-title" class="ca-modal-title">
          <span class="ca-modal-icon"><i data-lucide="target" class="h-5 w-5"></i></span>
          Assign Daily Target
        </h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-assign-daily-target" class="ca-modal-body space-y-4" novalidate>
        <input type="hidden" name="target_id" id="assign-daily-target-id" />
        <input type="hidden" name="employee_id" id="assign-daily-target-employee-id" value="" />
        <div><label class="form-label">Employee *</label>
          <select id="assign-daily-target-employee" class="input-field" data-crm-entity-lookup="employee" data-crm-lookup-placeholder="Search employee…" aria-required="true"><option value="">Select employee…</option></select>
        </div>
        <div><label class="form-label">Target Date *</label><input name="target_date" id="assign-daily-target-date" type="date" class="input-field" required data-crm-date-input /></div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="form-label">Lead Target</label><input name="lead_target" id="assign-daily-target-leads" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Call Target</label><input name="call_target" id="assign-daily-target-calls" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Demo Target</label><input name="demo_target" id="assign-daily-target-demos" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Follow-up Target</label><input name="followup_target" id="assign-daily-target-followups" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">Email Target <span class="text-slate-400">(optional)</span></label><input name="email_target" id="assign-daily-target-email" type="number" min="0" step="1" class="input-field" value="0" /></div>
          <div><label class="form-label">SMS Target <span class="text-slate-400">(optional)</span></label><input name="sms_target" id="assign-daily-target-sms" type="number" min="0" step="1" class="input-field" value="0" /></div>
        </div>
        <div><label class="form-label">Notes / Instructions</label><textarea name="notes" id="assign-daily-target-notes" class="input-field" rows="3" placeholder="Instructions for the employee…"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons flex-wrap">
          <button type="button" class="btn-secondary hidden" id="assign-daily-target-copy-team">Copy to Entire Team</button>
          <button type="button" class="btn-secondary hidden" id="assign-daily-target-copy-weekdays">Repeat Weekdays</button>
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-assign-daily-target" class="btn-primary flex-1" id="assign-daily-target-save">Save Target</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-demo-calendar-schedule" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="demo-cal-schedule-title">
    <div class="ca-modal-panel ca-modal-panel-xl">
      <div class="ca-modal-header">
        <h3 id="demo-cal-schedule-title" class="ca-modal-title"><i data-lucide="video" class="h-5 w-5 text-brand"></i> Schedule Demo</h3>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <form id="form-demo-calendar-schedule" class="ca-modal-body space-y-4">
        <div><label class="form-label">Lead / Firm *</label>
          <select id="demo-cal-schedule-lead" class="input-field" data-crm-entity-lookup="lead" data-crm-lookup-placeholder="Search lead…" required><option value="">Select lead…</option></select>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
          <div><label class="form-label">Demo Provider *</label><select id="demo-cal-schedule-provider" class="input-field" required></select></div>
          <div><label class="form-label">Team Size</label><input id="demo-cal-schedule-team-size" type="number" min="1" class="input-field" /></div>
          <div><label class="form-label">Demo Date *</label><input id="demo-cal-schedule-date" type="date" class="input-field" required data-crm-date-input /></div>
          <div><label class="form-label">Start Time *</label><input id="demo-cal-schedule-start" type="time" class="input-field" required /></div>
          <div><label class="form-label">End Time</label><input id="demo-cal-schedule-end" type="time" class="input-field" /></div>
          <div><label class="form-label">Meeting Link</label><input id="demo-cal-schedule-link" type="url" class="input-field" placeholder="https://meet.google.com/..." /></div>
        </div>
        <div><label class="form-label">Notes</label><textarea id="demo-cal-schedule-notes" class="input-field" rows="3"></textarea></div>
      </form>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-secondary" data-close-crm-modal>Cancel</button>
          <button type="submit" form="form-demo-calendar-schedule" class="btn-primary">Save Demo</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-dcp-detail" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="dcp-detail-title">
    <div class="ca-modal-panel ca-modal-panel-xl">
      <div class="ca-modal-header">
        <h3 id="dcp-detail-title" class="ca-modal-title"><i data-lucide="presentation" class="h-5 w-5 text-brand"></i> Demo Details</h3>
        <span class="badge-brand ml-2">Demo Mode</span>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body dcp-detail-body">
        <div class="dcp-detail-grid">
          <div class="dcp-detail-row"><span class="dcp-detail-label">Firm Name</span><span id="dcp-detail-firm" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">CA Name</span><span id="dcp-detail-ca" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Assigned Executive</span><span id="dcp-detail-employee" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Phone Number</span><span id="dcp-detail-phone" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Demo Date</span><span id="dcp-detail-date" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Demo Time</span><span id="dcp-detail-time" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Meeting Link</span><a id="dcp-detail-meeting" class="dcp-detail-link" href="#" target="_blank" rel="noopener noreferrer"></a></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Demo Status</span><span id="dcp-detail-status" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Priority</span><span id="dcp-detail-priority" class="dcp-detail-value"></span></div>
          <div class="dcp-detail-row"><span class="dcp-detail-label">Last Follow-up</span><span id="dcp-detail-followup" class="dcp-detail-value"></span></div>
        </div>
        <div class="dcp-detail-row dcp-detail-row--block"><span class="dcp-detail-label">Remarks</span><p id="dcp-detail-desc" class="dcp-detail-remarks"></p></div>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons dcp-detail-actions">
          <button type="button" class="btn-primary btn-sm" id="dcp-action-start"><i data-lucide="play" class="h-4 w-4"></i> Start Demo</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-action-complete"><i data-lucide="check-circle" class="h-4 w-4"></i> Mark Completed</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-action-reschedule"><i data-lucide="calendar-clock" class="h-4 w-4"></i> Reschedule</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-action-edit"><i data-lucide="pencil" class="h-4 w-4"></i> Edit Demo</button>
          <button type="button" class="btn-secondary btn-sm text-rose-600 border-rose-200" id="dcp-action-cancel"><i data-lucide="x-circle" class="h-4 w-4"></i> Cancel Demo</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-action-followup"><i data-lucide="phone-forwarded" class="h-4 w-4"></i> Add Follow-up</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-detail-close">Close</button>
        </div>
      </div>
    </div>
  </div>

  <div id="modal-dcp-form" class="ca-modal" role="dialog" aria-modal="true" aria-labelledby="dcp-form-title">
    <div class="ca-modal-panel ca-modal-panel-lg">
      <div class="ca-modal-header">
        <h3 id="dcp-form-title" class="ca-modal-title">Schedule Demo</h3>
        <span class="badge-brand ml-2">Demo Mode</span>
        <button type="button" class="ca-modal-close" data-close-crm-modal aria-label="Close"><i data-lucide="x" class="h-5 w-5"></i></button>
      </div>
      <div class="ca-modal-body">
        <p class="dcp-form-hours-note">Working hours: Mon–Sat, 10:00 AM – 7:00 PM. Sundays are closed.</p>
        <div class="dcp-form-grid">
          <label class="dcp-form-field">Firm Name<input type="text" id="dcp-form-firm" class="input-field" autocomplete="organization" /></label>
          <label class="dcp-form-field">CA Name<input type="text" id="dcp-form-ca" class="input-field" autocomplete="name" /></label>
          <label class="dcp-form-field">Assigned Executive<input type="text" id="dcp-form-executive" class="input-field" /></label>
          <label class="dcp-form-field">Phone<input type="tel" id="dcp-form-phone" class="input-field" autocomplete="tel" /></label>
          <label class="dcp-form-field">Demo Date<input type="date" id="dcp-form-date" class="input-field" /></label>
          <label class="dcp-form-field">Start Time<select id="dcp-form-start" class="input-field"></select></label>
          <label class="dcp-form-field">End Time<select id="dcp-form-end" class="input-field"></select></label>
          <label class="dcp-form-field">Priority
            <select id="dcp-form-priority" class="input-field">
              <option value="high">High</option>
              <option value="medium" selected>Medium</option>
              <option value="low">Low</option>
            </select>
          </label>
          <label class="dcp-form-field dcp-form-field--full">Remarks<textarea id="dcp-form-remarks" class="input-field" rows="2"></textarea></label>
        </div>
        <p id="dcp-form-error" class="dcp-form-error" role="alert"></p>
      </div>
      <div class="ca-modal-footer">
        <div class="ca-modal-footer-buttons">
          <button type="button" class="btn-primary btn-sm" id="dcp-form-save">Save Demo</button>
          <button type="button" class="btn-secondary btn-sm" id="dcp-form-cancel">Cancel</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container" aria-live="polite"></div>
