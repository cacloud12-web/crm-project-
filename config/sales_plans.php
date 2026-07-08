<?php

return [
  'default_plan' => 'CRM Annual',

  'plans' => [
      'CRM Annual' => [
          'duration_months' => 12,
          'cooling_period_days' => 15,
          'points' => 10,
          'default_amount' => 25000,
      ],
      'CRM Half-Yearly' => [
          'duration_months' => 6,
          'cooling_period_days' => 7,
          'points' => 6,
          'default_amount' => 15000,
      ],
      'CRM Quarterly' => [
          'duration_months' => 3,
          'cooling_period_days' => 7,
          'points' => 3,
          'default_amount' => 8000,
      ],
      'CRM Monthly' => [
          'duration_months' => 1,
          'cooling_period_days' => 3,
          'points' => 1,
          'default_amount' => 3000,
      ],
  ],

  'payment_statuses' => ['Pending', 'Partial', 'Paid', 'Overdue'],
];
