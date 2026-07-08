<?php

return [
    'groups' => [
        'Lead Variables' => [
            ['key' => '{{CA_NAME}}', 'label' => 'CA Name'],
            ['key' => '{{FIRM_NAME}}', 'label' => 'Firm Name'],
            ['key' => '{{CITY}}', 'label' => 'City'],
            ['key' => '{{STATE}}', 'label' => 'State'],
            ['key' => '{{ADDRESS}}', 'label' => 'Address'],
            ['key' => '{{PINCODE}}', 'label' => 'Pincode'],
            ['key' => '{{MOBILE}}', 'label' => 'Mobile'],
            ['key' => '{{EMAIL}}', 'label' => 'Email'],
            ['key' => '{{SOURCE}}', 'label' => 'Source'],
        ],
        'Employee Variables' => [
            ['key' => '{{EMPLOYEE_NAME}}', 'label' => 'Employee Name'],
            ['key' => '{{EMPLOYEE_EMAIL}}', 'label' => 'Employee Email'],
            ['key' => '{{EMPLOYEE_PHONE}}', 'label' => 'Employee Phone'],
            ['key' => '{{EMPLOYEE_ROLE}}', 'label' => 'Employee Role'],
        ],
        'Demo Variables' => [
            ['key' => '{{DEMO_DATE}}', 'label' => 'Demo Date'],
            ['key' => '{{DEMO_TIME}}', 'label' => 'Demo Time'],
            ['key' => '{{MEETING_LINK}}', 'label' => 'Meeting Link'],
            ['key' => '{{DEMO_PROVIDER}}', 'label' => 'Demo Provider'],
            ['key' => '{{TEAM_SIZE}}', 'label' => 'Team Size'],
        ],
        'Follow-up Variables' => [
            ['key' => '{{FOLLOWUP_DATE}}', 'label' => 'Follow-up Date'],
            ['key' => '{{FOLLOWUP_TIME}}', 'label' => 'Follow-up Time'],
            ['key' => '{{NEXT_ACTION}}', 'label' => 'Next Action'],
            ['key' => '{{REMARKS}}', 'label' => 'Remarks'],
            ['key' => '{{FOLLOWUP_TYPE}}', 'label' => 'Follow-up Type'],
        ],
        'Sales Variables' => [
            ['key' => '{{PLAN_NAME}}', 'label' => 'Plan Name'],
            ['key' => '{{AMOUNT}}', 'label' => 'Amount'],
            ['key' => '{{BALANCE}}', 'label' => 'Balance'],
            ['key' => '{{INVOICE_NO}}', 'label' => 'Invoice No'],
            ['key' => '{{PURCHASE_DATE}}', 'label' => 'Purchase Date'],
            ['key' => '{{COOLING_PERIOD}}', 'label' => 'Cooling Period'],
            ['key' => '{{EXPIRY_DATE}}', 'label' => 'Expiry Date'],
            ['key' => '{{PAYMENT_STATUS}}', 'label' => 'Payment Status'],
        ],
        'Company Variables' => [
            ['key' => '{{COMPANY_NAME}}', 'label' => 'Company Name'],
            ['key' => '{{SUPPORT_EMAIL}}', 'label' => 'Support Email'],
            ['key' => '{{SUPPORT_PHONE}}', 'label' => 'Support Phone'],
            ['key' => '{{WEBSITE}}', 'label' => 'Website'],
            ['key' => '{{COMPANY_ADDRESS}}', 'label' => 'Company Address'],
        ],
    ],

    'categories' => [
        'Lead',
        'Follow-up',
        'Demo',
        'Sales',
        'Invoice',
        'Reminder',
        'Renewal',
        'Support',
        'Marketing',
        'General',
    ],

    'publish_statuses' => ['draft', 'active', 'disabled'],
];
