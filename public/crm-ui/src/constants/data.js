/* CA Cloud Desk — optional offline demo data (disabled unless window.CRM_USE_DEMO_FALLBACKS = true) */
window.CAData = (function () {
  'use strict';

  var DEMO_ENABLED = typeof window !== 'undefined' && window.CRM_USE_DEMO_FALLBACKS === true;

  var manager = {
    employee_id: 'emp-mgr',
    name: 'Demo Manager',
    email_id: 'demo.manager@example.local',
    mobile_no: '+91 90000 00001',
    role_id: 'role-02',
    role: 'Sales Manager',
    reporting_manager_id: null,
    city: 'Mumbai',
    status: 'Active',
  };

  var executives = [
    { employee_id: 'emp-01', name: 'Demo Employee 1', email_id: 'demo.employee1@example.local', mobile_no: '+91 90000 00002', role_id: 'role-03', role: 'Employee', reporting_manager_id: 'emp-mgr', manager: 'Demo Manager', city: 'Mumbai', date_of_joining: '2023-03-15', status: 'Active', target_leads: 40, achieved_leads: 38, daily_calls: 42, demos: 10, conversion: '28%', revenue: '₹6.2L' },
    { employee_id: 'emp-02', name: 'Demo Employee 2', email_id: 'demo.employee2@example.local', mobile_no: '+91 90000 00003', role_id: 'role-03', role: 'Employee', reporting_manager_id: 'emp-mgr', manager: 'Demo Manager', city: 'Pune', date_of_joining: '2023-06-01', status: 'Active', target_leads: 35, achieved_leads: 32, daily_calls: 38, demos: 9, conversion: '26%', revenue: '₹5.1L' },
    { employee_id: 'emp-03', name: 'Demo Employee 3', email_id: 'demo.employee3@example.local', mobile_no: '+91 90000 00004', role_id: 'role-03', role: 'Employee', reporting_manager_id: 'emp-mgr', manager: 'Demo Manager', city: 'Bangalore', date_of_joining: '2023-09-10', status: 'Active', target_leads: 38, achieved_leads: 34, daily_calls: 40, demos: 11, conversion: '30%', revenue: '₹5.8L' },
  ];

  var leads = [
    { ca_id: 'a1b2c3d4-e5f6-7890-abcd-ef1234560001', firm_name: 'Demo Firm 1', ca_name: 'Demo CA 1', mobile_no: '+91 90000 00010', email_id: 'demo1@example.local', gst_no: '00AAAAA0000A1Z5', state: 'Sample State', city: 'Sample City', team_size: 12, existing_software: 'Tally', website: 'example.com', rating: 5, is_newly_established: true, status: 'Hot', source: 'Website', stage: 'Negotiation', executive_id: 'emp-01', executive: 'Demo Employee 1', priority: 5, last_action: 'Call', updated: '2m ago', assignment_type: 'Auto', rotation_logic: 'Hot First' },
    { ca_id: 'b3c4d5e6-f7a8-9012-bcde-f1234560002', firm_name: 'Demo Firm 2', ca_name: 'Demo CA 2', mobile_no: '+91 90000 00011', email_id: 'demo2@example.local', gst_no: '00BBBBB0000B1Z5', state: 'Sample State', city: 'Sample City', team_size: 8, existing_software: 'Zoho', website: 'example.com', rating: 4, is_newly_established: false, status: 'Demo Scheduled', source: 'Referral', stage: 'Demo Scheduled', executive_id: 'emp-02', executive: 'Demo Employee 2', priority: 4, last_action: 'Details Shared', updated: '1h ago', assignment_type: 'Auto', rotation_logic: 'Round Robin' },
    { ca_id: 'c5d6e7f8-a9b0-1234-cdef-1234560003', firm_name: 'Demo Firm 3', ca_name: 'Demo CA 3', mobile_no: '+91 90000 00012', email_id: 'demo3@example.local', gst_no: '00CCCCC0000C1Z5', state: 'Sample State', city: 'Sample City', team_size: 15, existing_software: 'Tally', website: 'example.com', rating: 5, is_newly_established: false, status: 'Active', source: 'Exhibition', stage: 'Won', executive_id: 'emp-03', executive: 'Demo Employee 3', priority: 5, last_action: 'Payment', updated: '3h ago', assignment_type: 'Auto', rotation_logic: 'Workload' },
  ];
  ['2026-06-19', '2026-06-18', '2026-06-17', '2026-06-10', '2026-06-16', '2026-06-14', '2026-06-19', '2026-06-08', '2026-06-12', '2026-05-28'].forEach(function (d, i) {
    if (leads[i]) leads[i].created_at = d;
  });

  var assignments = [];
  var followups = [];
  var activityLog = [
    { log_id: 'log-seed-1', user: 'Demo Manager', module: 'LEAD_ASSIGNMENT_ENGINE', record_id: 'a1b2…01', action: 'Assign', detail: 'Demo firm → Demo Employee 1', time: '2m ago' },
    { log_id: 'log-seed-2', user: 'Demo Employee 1', module: 'FOLLOW_UP_MANAGEMENT', record_id: 'fu-201', action: 'Insert', detail: 'Demo Scheduled', time: '15m ago' },
    { log_id: 'log-seed-3', user: 'System', module: 'WHATSAPP_CAMPAIGN', record_id: 'wa-8821', action: 'Send', detail: 'Demo reminder delivered', time: '1h ago' },
  ];

  var notifications = [
    { notification_id: 'ntf-01', title: 'New hot lead assigned', message: 'Demo Firm 1 — Sample City', time: '2 min ago', type: 'brand', read: false },
    { notification_id: 'ntf-02', title: 'Payment received', message: '₹24,999 from Demo Firm 3', time: '1 hr ago', type: 'emerald', read: false },
    { notification_id: 'ntf-03', title: 'Follow-up overdue', message: 'Demo Firm 2 — 2 hrs overdue', time: '3 hrs ago', type: 'amber', read: false },
    { notification_id: 'ntf-04', title: 'Demo confirmed via WhatsApp', message: 'Demo Firm 2 — Jun 18 10:30', time: '5 hrs ago', type: 'brand', read: false },
    { notification_id: 'ntf-05', title: 'Lead assigned to employee', message: 'Demo Firm 1 → Demo Employee 1', time: '6 hrs ago', type: 'brand', read: false },
    { notification_id: 'ntf-06', title: 'SMS campaign delivered', message: 'Festival greeting — 248 recipients', time: '7 hrs ago', type: 'emerald', read: false },
    { notification_id: 'ntf-07', title: 'Demo scheduled', message: 'Demo Firm 3 — Jun 20', time: '8 hrs ago', type: 'brand', read: false },
    { notification_id: 'ntf-08', title: 'Payment reminder sent', message: 'Demo Firm 2 — ₹18,500 pending', time: '9 hrs ago', type: 'amber', read: false },
    { notification_id: 'ntf-09', title: 'New lead from website', message: 'Demo Firm 1 — Sample City', time: '10 hrs ago', type: 'brand', read: false },
    { notification_id: 'ntf-10', title: 'Employee target alert', message: 'Demo Employee 2 at 87% monthly target', time: '11 hrs ago', type: 'amber', read: false },
    { notification_id: 'ntf-11', title: 'WhatsApp message read', message: 'Demo Firm 2 viewed proposal', time: '12 hrs ago', type: 'emerald', read: false },
    { notification_id: 'ntf-12', title: 'System maintenance notice', message: 'Scheduled backup tonight 11 PM', time: '1 day ago', type: 'brand', read: false },
  ];

  var campaigns = [
    { campaign_id: 'cmp-01', channel: 'whatsapp', name: 'Demo Confirmation', campaign_type: 'Demo Confirmation', template_id: 'tpl-1', subject: '', audience: 'Hot Leads', created_by: 'Demo Manager', status: 'Active', sent: 128, delivered: 126, read: 98 },
    { campaign_id: 'cmp-02', channel: 'whatsapp', name: 'Demo Reminder', campaign_type: 'Demo Reminder', template_id: 'tpl-2', subject: '', audience: 'Pipeline', created_by: 'Demo Manager', status: 'Active', sent: 96, delivered: 94, read: 81 },
    { campaign_id: 'cmp-03', channel: 'whatsapp', name: 'Brochure Sharing', campaign_type: 'Brochure Sharing', template_id: 'tpl-3', subject: '', audience: 'All Leads', created_by: 'Demo Manager', status: 'Active', sent: 74, delivered: 72, read: 58 },
    { campaign_id: 'cmp-04', channel: 'whatsapp', name: 'Feature Videos', campaign_type: 'Feature Videos', template_id: 'tpl-4', subject: '', audience: 'Warm Leads', created_by: 'Demo Manager', status: 'Active', sent: 52, delivered: 51, read: 44 },
    { campaign_id: 'cmp-05', channel: 'whatsapp', name: 'Webinar Invitation', campaign_type: 'Webinar Invitation', template_id: 'tpl-5', subject: '', audience: 'All Leads', created_by: 'Demo Manager', status: 'Active', sent: 88, delivered: 86, read: 62 },
    { campaign_id: 'cmp-06', channel: 'whatsapp', name: 'Trial Activation', campaign_type: 'Trial Activation', template_id: 'tpl-6', subject: '', audience: 'New Leads', created_by: 'Demo Manager', status: 'Active', sent: 41, delivered: 40, read: 35 },
    { campaign_id: 'cmp-07', channel: 'whatsapp', name: 'Payment Receive', campaign_type: 'Payment Receive', template_id: 'tpl-7', subject: '', audience: 'Negotiation', created_by: 'Demo Manager', status: 'Active', sent: 33, delivered: 33, read: 29 },
    { campaign_id: 'cmp-08', channel: 'whatsapp', name: 'Renewal Reminder', campaign_type: 'Renewal Reminder', template_id: 'tpl-8', subject: '', audience: 'Active Clients', created_by: 'Demo Manager', status: 'Active', sent: 67, delivered: 65, read: 51 },
    { campaign_id: 'cmp-09', channel: 'whatsapp', name: 'Festival Greeting', campaign_type: 'Festival Greeting', template_id: 'tpl-9', subject: '', audience: 'All Leads', created_by: 'Demo Manager', status: 'Active', sent: 210, delivered: 205, read: 178 },
    { campaign_id: 'cmp-10', channel: 'sms', name: 'Demo Reminder', campaign_type: 'Demo Reminder', template_id: 'sms-tpl-1', subject: '', audience: 'Hot Leads', created_by: 'Demo Manager', status: 'Active', sent: 420, delivered: 412, read: 0 },
    { campaign_id: 'cmp-11', channel: 'sms', name: 'Payment Link', campaign_type: 'Payment Link', template_id: 'sms-tpl-2', subject: '', audience: 'Negotiation', created_by: 'Demo Manager', status: 'Active', sent: 186, delivered: 181, read: 0 },
    { campaign_id: 'cmp-12', channel: 'sms', name: 'Festival Greeting', campaign_type: 'Festival Greeting', template_id: 'sms-tpl-3', subject: '', audience: 'All Leads', created_by: 'Demo Manager', status: 'Active', sent: 248, delivered: 230, read: 0 },
    { campaign_id: 'cmp-13', channel: 'email', name: 'Bulk Email', campaign_type: 'Bulk Email', template_id: 'em-tpl-1', subject: 'New features for CA firms', audience: 'All Leads', created_by: 'Demo Manager', status: 'Active', sent: 890, delivered: 872, read: 412 },
    { campaign_id: 'cmp-14', channel: 'email', name: 'Proposal Templates', campaign_type: 'Proposal Templates', template_id: 'em-tpl-2', subject: 'CA Cloud Desk Proposal', audience: 'Pipeline', created_by: 'Demo Manager', status: 'Active', sent: 156, delivered: 154, read: 98 },
    { campaign_id: 'cmp-15', channel: 'email', name: 'Demo Follow-up Sequence', campaign_type: 'Demo Follow-up Sequence', template_id: 'em-tpl-3', subject: 'Following up on your demo', audience: 'Demo Scheduled', created_by: 'Demo Manager', status: 'Active', sent: 203, delivered: 199, read: 121 },
  ];

  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function shortId(id) { return id ? id.slice(0, 4) + '…' + id.slice(-2) : '—'; }

  function logActivity(module, recordId, action, detail) {
    activityLog.unshift({
      log_id: 'log-' + Date.now(),
      user: manager.name,
      module: module,
      record_id: recordId,
      action: action,
      detail: detail || '',
      time: 'Just now',
    });
    if (activityLog.length > 20) activityLog.pop();
  }

  function filterLeads(segment, prefs) {
    var list;
    if (!segment || segment === 'all') list = leads.slice();
    else if (segment === 'new') list = leads.filter(function (l) { return l.status === 'New' || l.stage === 'New Lead'; });
    else if (segment === 'hot') list = leads.filter(function (l) { return l.status === 'Hot'; });
    else if (segment === 'lost') list = leads.filter(function (l) { return l.status === 'Lost' || l.stage === 'Lost'; });
    else if (segment === 'pipeline') {
      list = leads.filter(function (l) {
        return ['Pipeline', 'Warm', 'Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.status) >= 0 ||
          ['Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation'].indexOf(l.stage) >= 0;
      });
    } else list = leads.slice();

    if (!prefs) return list;

    if (prefs.city) {
      list = list.filter(function (l) { return l.city === prefs.city; });
    }
    if (prefs.dateFrom) {
      list = list.filter(function (l) { return l.created_at && l.created_at >= prefs.dateFrom; });
    }
    if (prefs.dateTo) {
      list = list.filter(function (l) { return l.created_at && l.created_at <= prefs.dateTo; });
    }
    return list;
  }

  function getLeadCounts() {
    return {
      all: leads.length,
      new: filterLeads('new').length,
      hot: filterLeads('hot').length,
      pipeline: filterLeads('pipeline').length,
      lost: filterLeads('lost').length,
    };
  }

  function getMetrics() {
    var counts = getLeadCounts();
    var hot = counts.hot;
    var pipeline = counts.pipeline;
    var lost = counts.lost;
    var demos = leads.filter(function (l) { return l.stage.indexOf('Demo') >= 0 || l.status.indexOf('Demo') >= 0; }).length;
    var totalCalls = executives.reduce(function (s, e) { return s + e.daily_calls; }, 0);
    var achieved = executives.reduce(function (s, e) { return s + e.achieved_leads; }, 0);
    var target = executives.reduce(function (s, e) { return s + e.target_leads; }, 0);
    return {
      total_leads: leads.length,
      total_calls: totalCalls,
      demo_count: demos,
      demo_ratio: leads.length ? ((demos / leads.length) * 100).toFixed(1) + '%' : '0%',
      conversion: '24.8%',
      hot_leads: hot,
      pipeline: pipeline,
      lost_leads: lost,
      target_achievement: target ? ((achieved / target) * 100).toFixed(1) + '%' : '0%',
      active_executives: executives.filter(function (e) { return e.status === 'Active'; }).length,
    };
  }

  function addLead(data) {
    var lead = {
      ca_id: uuid(),
      firm_name: data.firm_name,
      ca_name: data.ca_name,
      mobile_no: data.mobile_no,
      email_id: data.email_id,
      gst_no: data.gst_no || '—',
      state: data.state,
      city: data.city,
      team_size: (function () {
        var n = parseInt(data.team_size, 10);
        return !isNaN(n) && n > 0 ? n : null;
      })(),
      existing_software: data.existing_software || 'None',
      website: data.website || '—',
      rating: parseInt(data.rating, 10) || 3,
      is_newly_established: data.is_newly_established === 'yes',
      status: data.status || 'New',
      source: data.source || 'Website',
      stage: 'New Lead',
      executive_id: data.executive_id || null,
      executive: data.executive_id ? (executives.find(function (e) { return e.employee_id === data.executive_id; }) || {}).name || 'Unassigned' : 'Unassigned',
      priority: parseInt(data.rating, 10) || 3,
      last_action: '—',
      updated: 'Just now',
      created_at: new Date().toISOString().slice(0, 10),
      assignment_type: data.executive_id ? 'Manual' : 'Pending',
      rotation_logic: '—',
    };
    leads.unshift(lead);
    logActivity('CA_MASTER', shortId(lead.ca_id), 'Insert', lead.firm_name);
    return lead;
  }

  function updateLead(caId, data) {
    var lead = leads.find(function (l) { return l.ca_id === caId; });
    if (!lead) return null;

    lead.firm_name = data.firm_name;
    lead.ca_name = data.ca_name;
    lead.mobile_no = data.mobile_no;
    lead.email_id = data.email_id;
    lead.gst_no = data.gst_no || '—';
    lead.state = data.state;
    lead.city = data.city;
    var parsedTeamSize = parseInt(data.team_size, 10);
    lead.team_size = !isNaN(parsedTeamSize) && parsedTeamSize > 0 ? parsedTeamSize : null;
    lead.existing_software = data.existing_software || 'None';
    lead.website = data.website || '—';
    lead.rating = parseInt(data.rating, 10) || 3;
    lead.is_newly_established = data.is_newly_established === 'yes';
    lead.status = data.status || lead.status;
    lead.source = data.source || lead.source;
    lead.priority = parseInt(data.rating, 10) || lead.priority;
    lead.updated = 'Just now';

    if (data.executive_id) {
      var exec = executives.find(function (e) { return e.employee_id === data.executive_id; });
      lead.executive_id = data.executive_id;
      lead.executive = exec ? exec.name : lead.executive;
      lead.assignment_type = lead.assignment_type === 'Pending' ? 'Manual' : lead.assignment_type;
    } else if (data.executive_id === '') {
      lead.executive_id = null;
      lead.executive = 'Unassigned';
    }

    if (lead.status === 'Hot' && lead.stage === 'New Lead') lead.stage = 'Negotiation';
    if (lead.status === 'Demo Scheduled') lead.stage = 'Demo Scheduled';
    if (lead.status === 'Pipeline') lead.stage = 'Details Shared';
    if (lead.status === 'Lost') lead.stage = 'Lost';
    if (lead.status === 'Inactive') lead.stage = 'Lost';

    logActivity('CA_MASTER', shortId(caId), 'Update', lead.firm_name);
    return lead;
  }

  function addEmployee(data) {
    var emp = {
      employee_id: 'emp-' + String(executives.length + 1).padStart(2, '0'),
      name: data.name,
      email_id: data.email_id,
      mobile_no: data.mobile_no,
      role_id: 'role-03',
      role: 'Employee',
      reporting_manager_id: 'emp-mgr',
      manager: manager.name,
      city: data.city,
      date_of_joining: data.date_of_joining || new Date().toISOString().slice(0, 10),
      status: 'Active',
      target_leads: parseInt(data.target_leads, 10) || 30,
      achieved_leads: 0,
      daily_calls: 0,
      demos: 0,
      conversion: '0%',
      revenue: '₹0',
    };
    executives.push(emp);
    logActivity('EMPLOYEE_MASTER', emp.employee_id, 'Insert', emp.name);
    return emp;
  }

  function assignLead(caId, executiveId, type, reason) {
    var lead = leads.find(function (l) { return l.ca_id === caId; });
    var exec = executives.find(function (e) { return e.employee_id === executiveId; });
    if (!lead || !exec) return null;
    var hist = {
      history_id: 'hist-' + Date.now(),
      from: lead.executive || 'Unassigned',
      to: exec.name,
      lead: lead.firm_name,
      by: manager.name,
      reason: reason || 'MANUAL_ASSIGN',
      date: new Date().toLocaleDateString('en-IN', { month: 'short', day: 'numeric' }),
    };
    lead.executive_id = executiveId;
    lead.executive = exec.name;
    lead.assignment_type = type || 'Manual';
    lead.updated = 'Just now';
    assignments.unshift(hist);
    exec.achieved_leads = Math.min(exec.achieved_leads + 1, exec.target_leads);
    logActivity('LEAD_ASSIGNMENT_ENGINE', shortId(lead.ca_id), 'Assign', exec.name);
    return hist;
  }

  function applyLeadAction(caId, action) {
    var lead = leads.find(function (l) { return l.ca_id === caId; });
    if (!lead) return;
    lead.last_action = action;
    lead.updated = 'Just now';
    if (action === 'Move to Demo Tab') { lead.stage = 'Demo Scheduled'; lead.status = 'Demo Scheduled'; }
    else if (action === 'Not Interested') { lead.stage = 'Lost'; lead.status = 'Lost'; }
    else if (action === 'Pipeline') { lead.stage = 'Details Shared'; lead.status = 'Pipeline'; }
    else if (action === 'Mark Inactive') { lead.status = 'Inactive'; }
    else if (action === 'Details Shared') { lead.stage = 'Details Shared'; lead.status = 'Warm'; }
    logActivity('LEAD_ACTION', shortId(caId), 'Update', action);
  }

  function addFollowup(data) {
    var fu = {
      followup_id: 'fu-' + Date.now(),
      followup_type: data.followup_type,
      firm: data.firm,
      executive: data.executive,
      remarks: data.remarks,
      scheduled_date: data.scheduled_date,
      next_followup_date: data.next_followup_date,
      status: 'Scheduled',
    };
    followups.unshift(fu);
    logActivity('FOLLOW_UP_MANAGEMENT', fu.followup_id, 'Insert', data.followup_type);
    return fu;
  }

  function leadToRowData(lead) {
    return {
      id: lead.ca_id,
      firm: lead.firm_name,
      ca: lead.ca_name,
      mobile: lead.mobile_no,
      alternateMobile: lead.alternate_mobile_no,
      email: lead.email_id,
      gst: lead.gst_no,
      state: lead.state,
      city: lead.city,
      team: (lead.team_size != null && lead.team_size !== '' && Number(lead.team_size) > 0)
        ? (Number(lead.team_size) + ' Employees')
        : '—',
      software: lead.existing_software,
      website: lead.website,
      rating: lead.rating,
      newFirm: lead.is_newly_established,
      status: lead.status,
      source: lead.source,
      executive: lead.executive,
      stage: lead.stage,
      priority: lead.priority,
      last_action: lead.last_action,
    };
  }

  function getPipelineBreakdown() {
    var stages = ['New Lead', 'Details Shared', 'Demo Scheduled', 'Demo Completed', 'Negotiation', 'Won', 'Lost'];
    return stages.map(function (s) {
      return { stage: s, count: leads.filter(function (l) { return l.stage === s; }).length };
    });
  }

  function getCityBreakdown() {
    var map = {};
    leads.forEach(function (l) { map[l.city] = (map[l.city] || 0) + 1; });
    return Object.keys(map).map(function (city) {
      return { city: city, count: map[city] };
    }).sort(function (a, b) { return b.count - a.count; });
  }

  function getSourceBreakdown() {
    var map = {};
    leads.forEach(function (l) { map[l.source] = (map[l.source] || 0) + 1; });
    return Object.keys(map).map(function (src) {
      return { source: src, count: map[src] };
    }).sort(function (a, b) { return b.count - a.count; });
  }

  function getPriorityLeads() {
    return leads.filter(function (l) {
      return l.status === 'Hot' || l.stage === 'Demo Scheduled' || l.stage === 'Negotiation';
    }).slice(0, 5);
  }

  function getNotifications() {
    return notifications.slice();
  }

  function getUnreadNotificationCount() {
    return notifications.filter(function (n) { return !n.read; }).length;
  }

  function markNotificationRead(id) {
    var item = notifications.find(function (n) { return n.notification_id === id; });
    if (item) item.read = true;
    return item;
  }

  function markAllNotificationsRead() {
    var unread = getUnreadNotificationCount();
    notifications.forEach(function (n) { n.read = true; });
    return unread;
  }

  var campaignTypeOptions = {
    whatsapp: ['Demo Confirmation', 'Demo Reminder', 'Brochure Sharing', 'Feature Videos', 'Webinar Invitation', 'Trial Activation', 'Payment Receive', 'Renewal Reminder', 'Festival Greeting'],
    sms: ['Demo Reminder', 'Payment Link', 'Festival Greeting', 'OTP Alert', 'Follow-up Reminder'],
    email: ['Bulk Email', 'Proposal Templates', 'Demo Follow-up Sequence', 'Newsletter', 'Renewal Notice'],
  };

  function getCampaigns(channel) {
    return campaigns.filter(function (c) { return !channel || c.channel === channel; }).slice();
  }

  function getCampaignById(id) {
    return campaigns.find(function (c) { return c.campaign_id === id; });
  }

  function addCampaign(data) {
    var channel = data.channel || 'whatsapp';
    var campaign = {
      campaign_id: 'cmp-' + String(campaigns.length + 1).padStart(2, '0'),
      channel: channel,
      name: data.name,
      campaign_type: data.campaign_type,
      template_id: data.template_id || 'tpl-new',
      subject: data.subject || '',
      audience: data.audience || 'All Leads',
      created_by: manager.name.split(' ')[0] + ' V.',
      status: 'Active',
      sent: 0,
      delivered: 0,
      read: 0,
    };
    campaigns.unshift(campaign);
    var module = channel === 'whatsapp' ? 'WHATSAPP_CAMPAIGN' : channel === 'sms' ? 'SMS_CAMPAIGN' : 'EMAIL_CAMPAIGN';
    logActivity(module, campaign.campaign_id, 'Insert', campaign.name + ' · ' + campaign.audience);
    return campaign;
  }

  function estimateAudienceSize(audience) {
    var sizes = {
      'All Leads': leads.length,
      'Hot Leads': filterLeads('hot').length,
      'Warm Leads': leads.filter(function (l) { return l.status === 'Warm'; }).length,
      'New Leads': filterLeads('new').length,
      'Pipeline': filterLeads('pipeline').length,
      'Demo Scheduled': leads.filter(function (l) { return l.status === 'Demo Scheduled' || l.stage === 'Demo Scheduled'; }).length,
      'Negotiation': leads.filter(function (l) { return l.stage === 'Negotiation'; }).length,
      'Active Clients': leads.filter(function (l) { return l.status === 'Active'; }).length,
    };
    return Math.max(sizes[audience] || 12, 8);
  }

  function launchCampaign(id) {
    var campaign = campaigns.find(function (c) { return c.campaign_id === id; });
    if (!campaign || campaign.sent > 0) return null;

    var sent = estimateAudienceSize(campaign.audience);
    var delivered = Math.max(1, Math.round(sent * (0.93 + Math.random() * 0.06)));
    var read = 0;

    if (campaign.channel === 'whatsapp') {
      read = Math.round(delivered * (0.62 + Math.random() * 0.18));
    } else if (campaign.channel === 'email') {
      read = Math.round(delivered * (0.28 + Math.random() * 0.14));
    } else {
      read = Math.round(delivered * (0.07 + Math.random() * 0.06));
    }

    campaign.sent = sent;
    campaign.delivered = delivered;
    campaign.read = read;
    campaign.status = 'Launched';

    var module = campaign.channel === 'whatsapp' ? 'WHATSAPP_CAMPAIGN' : campaign.channel === 'sms' ? 'SMS_CAMPAIGN' : 'EMAIL_CAMPAIGN';
    logActivity(module, campaign.campaign_id, 'Launch', campaign.name + ' · Sent ' + sent + ' · Delivered ' + delivered);
    return campaign;
  }

  function getChannelPerformance(channel) {
    var list = campaigns.filter(function (c) { return c.channel === channel && c.sent > 0; });
    var sent = list.reduce(function (sum, c) { return sum + c.sent; }, 0);
    var delivered = list.reduce(function (sum, c) { return sum + c.delivered; }, 0);
    var read = list.reduce(function (sum, c) { return sum + c.read; }, 0);
    var failed = Math.max(sent - delivered, 0);
    return {
      sent: sent,
      delivered: delivered,
      read: read,
      failed: failed,
      deliveryRate: sent ? (delivered / sent) * 100 : 0,
      openRate: sent ? (read / sent) * 100 : 0,
      clickRate: read ? (read * 0.38 / sent) * 100 : 0,
      bounceRate: sent ? (failed / sent) * 100 : 0,
      unsubscribed: Math.max(Math.round(failed * 0.35), 0),
    };
  }

  return {
    getManager: function () { return DEMO_ENABLED ? manager : { name: 'Manager', email_id: '' }; },
    getExecutives: function () { return DEMO_ENABLED ? executives.slice() : []; },
    getLeads: function () { return DEMO_ENABLED ? leads.slice() : []; },
    getAssignments: function () { return DEMO_ENABLED ? assignments.slice() : []; },
    getFollowups: function () { return DEMO_ENABLED ? followups.slice() : []; },
    getActivityLog: function () { return DEMO_ENABLED ? activityLog.slice() : []; },
    getMetrics: function () { return DEMO_ENABLED ? getMetrics() : {}; },
    addLead: addLead,
    updateLead: updateLead,
    addEmployee: addEmployee,
    assignLead: assignLead,
    applyLeadAction: applyLeadAction,
    addFollowup: addFollowup,
    shortId: shortId,
    leadToRowData: leadToRowData,
    getLeadById: function (id) {
      if (!DEMO_ENABLED) return undefined;
      return leads.find(function (l) { return l.ca_id === id; });
    },
    filterLeads: filterLeads,
    getLeadCounts: getLeadCounts,
    getPipelineBreakdown: function () { return DEMO_ENABLED ? getPipelineBreakdown() : []; },
    getCityBreakdown: function () { return DEMO_ENABLED ? getCityBreakdown() : []; },
    getSourceBreakdown: function () { return DEMO_ENABLED ? getSourceBreakdown() : []; },
    getPriorityLeads: function () { return DEMO_ENABLED ? getPriorityLeads() : []; },
    getNotifications: function () { return DEMO_ENABLED ? getNotifications() : []; },
    getUnreadNotificationCount: function () { return DEMO_ENABLED ? getUnreadNotificationCount() : 0; },
    markNotificationRead: markNotificationRead,
    markAllNotificationsRead: markAllNotificationsRead,
    getCampaigns: function (channel) { return DEMO_ENABLED ? getCampaigns(channel) : []; },
    getCampaignById: function (id) { return DEMO_ENABLED ? getCampaignById(id) : undefined; },
    getCampaignTypeOptions: function (channel) { return (campaignTypeOptions[channel] || []).slice(); },
    addCampaign: addCampaign,
    launchCampaign: launchCampaign,
    getChannelPerformance: getChannelPerformance,
    setSelectedLeadId: function (id) { window._caSelectedLeadId = id; },
    getSelectedLeadId: function () {
      if (window._caSelectedLeadId === '' || window._caSelectedLeadId === null) return '';
      return window._caSelectedLeadId || '';
    },
  };
})();
