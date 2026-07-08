/* Reusable guide / info card components for CRM help sections */
window.CAGuide = (function () {
  'use strict';

  function esc(s) {
    if (s == null) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  var VARIANT_META = {
    info: { icon: 'info', className: 'ca-guide-card--info' },
    warning: { icon: 'alert-triangle', className: 'ca-guide-card--warning' },
    success: { icon: 'check-circle-2', className: 'ca-guide-card--success' },
    steps: { icon: 'list-ordered', className: 'ca-guide-card--info' },
  };

  /**
   * @param {'info'|'warning'|'success'|'steps'} variant
   * @param {string} title
   * @param {string} bodyHtml
   */
  function card(variant, title, bodyHtml) {
    var meta = VARIANT_META[variant] || VARIANT_META.info;
    return (
      '<section class="ca-guide-card ' + meta.className + '">' +
        '<header class="ca-guide-card-head">' +
          '<span class="ca-guide-card-icon-wrap" aria-hidden="true"><i data-lucide="' + meta.icon + '" class="ca-guide-card-icon"></i></span>' +
          '<h3 class="ca-guide-card-title">' + esc(title) + '</h3>' +
        '</header>' +
        '<div class="ca-guide-card-body">' + bodyHtml + '</div>' +
      '</section>'
    );
  }

  /**
   * @param {Array<{title: string, body: string, examples?: string[], list?: string[]}>} fields
   */
  function fieldGuide(fields) {
    return fields.map(function (field) {
      var html = '<div class="ca-guide-field">';
      html += '<h4 class="ca-guide-field-title">' + esc(field.title) + '</h4>';
      html += '<p class="ca-guide-field-text">' + field.body + '</p>';
      if (field.examples && field.examples.length) {
        html += '<p class="ca-guide-field-label">Examples:</p><ul class="ca-guide-list">';
        field.examples.forEach(function (ex) {
          html += '<li><code>' + esc(ex) + '</code></li>';
        });
        html += '</ul>';
      }
      if (field.list && field.list.length) {
        html += '<ul class="ca-guide-list ca-guide-list--bullets">';
        field.list.forEach(function (item) {
          html += '<li>' + item + '</li>';
        });
        html += '</ul>';
      }
      if (field.ports && field.ports.length) {
        html += '<p class="ca-guide-field-label">' + esc(field.portsLabel || 'Common Ports:') + '</p><ul class="ca-guide-list">';
        field.ports.forEach(function (port) {
          html += '<li>' + port + '</li>';
        });
        html += '</ul>';
      }
      html += '</div>';
      return html;
    }).join('');
  }

  /**
   * @param {Array<{title: string, body?: string, items?: string[]}>} steps
   */
  function stepsBody(steps) {
    return '<ol class="ca-guide-steps">' + steps.map(function (step, index) {
      var inner = '<span class="ca-guide-step-num">' + (index + 1) + '</span>';
      inner += '<div class="ca-guide-step-content">';
      inner += '<p class="ca-guide-step-title">' + esc(step.title) + '</p>';
      if (step.body) inner += '<p class="ca-guide-field-text">' + step.body + '</p>';
      if (step.items && step.items.length) {
        inner += '<ul class="ca-guide-list">';
        step.items.forEach(function (item) {
          inner += '<li>' + item + '</li>';
        });
        inner += '</ul>';
      }
      inner += '</div>';
      return '<li class="ca-guide-step">' + inner + '</li>';
    }).join('') + '</ol>';
  }

  function bulletList(items) {
    return '<ul class="ca-guide-list ca-guide-list--bullets">' +
      items.map(function (item) { return '<li>' + item + '</li>'; }).join('') +
    '</ul>';
  }

  function checkList(items) {
    return '<ul class="ca-guide-list ca-guide-list--checks">' +
      items.map(function (item) { return '<li><i data-lucide="check" class="ca-guide-check-icon"></i><span>' + item + '</span></li>'; }).join('') +
    '</ul>';
  }

  function emailConfigurationSection() {
    var smtpFields = fieldGuide([
      {
        title: 'Email *',
        body: 'Enter the email address that will be used for sending emails from the CRM.',
        examples: ['yourname@company.com', 'support@company.com', 'sales@company.com'],
      },
      {
        title: 'Password *',
        body: 'Enter the App Password or SMTP Password of the email account.<br><strong>For Gmail:</strong> Use Google App Password instead of your normal Gmail password.',
      },
      {
        title: 'Host *',
        body: 'Enter the SMTP server provided by your email provider.',
        examples: ['smtp.gmail.com', 'smtp.office365.com', 'smtp.zoho.com', 'smtp.mailgun.org'],
      },
      {
        title: 'Port *',
        body: 'Enter the SMTP port.',
        portsLabel: 'Common Ports:',
        ports: ['<strong>465</strong> (SSL)', '<strong>587</strong> (TLS)', '<strong>25</strong> (Not Recommended)'],
      },
      {
        title: 'Default',
        body: 'Enable this if this email account should become the default sender for:',
        list: [
          'Email Campaigns',
          'Follow-up Emails',
          'Notifications',
          'System Emails',
          'Password Reset Emails',
        ],
      },
      {
        title: 'IMAP Enabled',
        body: 'Enable this if the CRM should receive emails from this mailbox. When enabled the CRM can:',
        list: [
          'Sync Inbox',
          'Detect Replies',
          'Track Customer Responses',
          'Mark Replied Leads',
          'Display Conversation History',
        ],
      },
      {
        title: 'IMAP Host',
        body: 'Enter the IMAP server.',
        examples: ['imap.gmail.com', 'outlook.office365.com', 'imap.zoho.com'],
      },
      {
        title: 'IMAP Port',
        body: 'Enter the IMAP port.',
        portsLabel: 'Common Values:',
        ports: ['<strong>993</strong> (SSL Recommended)', '<strong>143</strong> (TLS)'],
      },
    ]);

    var gmailSteps = stepsBody([
      { title: 'Enable 2-Step Verification.' },
      { title: 'Create an App Password.' },
      {
        title: 'Use:',
        items: [
          '<strong>SMTP Host:</strong> smtp.gmail.com',
          '<strong>SMTP Port:</strong> 465',
          '<strong>IMAP Host:</strong> imap.gmail.com',
          '<strong>IMAP Port:</strong> 993',
          '<strong>Encryption:</strong> SSL',
        ],
      },
      { title: 'Click Test SMTP.' },
      { title: 'Click Save.' },
    ]);

    return (
      '<div class="ca-guide-grid">' +
        card('info', 'SMTP Configuration Guide', '<p class="ca-guide-intro">Understand every field before saving your communication email account.</p>' + smtpFields) +
        card('steps', 'Quick Gmail Setup', gmailSteps) +
        card('warning', 'Important Notes', bulletList([
          'Never use your Gmail login password.',
          'Always use App Password.',
          'Only one SMTP configuration can be Default.',
          'IMAP is optional but recommended.',
          'Test SMTP before saving.',
          'Test IMAP after enabling IMAP.',
        ])) +
        card('success', 'Best Practice', checkList([
          'Test SMTP before using campaigns.',
          'Enable IMAP to sync replies.',
          'Use company email instead of personal email.',
          'Regularly verify SMTP credentials.',
          'Monitor failed emails in Email Logs.',
        ])) +
      '</div>'
    );
  }

  return {
    card: card,
    fieldGuide: fieldGuide,
    stepsBody: stepsBody,
    bulletList: bulletList,
    checkList: checkList,
    emailConfigurationSection: emailConfigurationSection,
  };
})();
