<?php

return [
    'label' => 'Sale Date',
    'today' => 'Today',
    'recorded_at' => 'Recorded at',
    'warning' => 'You are recording a sale for a past date. The entry timestamp will be stored separately.',
    'confirm' => 'I confirm the selected historical sale date.',
    'confirm_required' => 'Confirm the historical sale date before completing the sale.',
    'invalid' => 'Enter a valid sale date in YYYY-MM-DD format.',
    'future' => 'The sale date cannot be in the future.',
    'unauthorized' => 'You are not authorized to record historical sales. Use today’s date.',
    'closed' => 'The selected business date or current recording date has a closed cashbook session.',
    'limit' => 'Sales may be backdated by at most :days days.',
    'branch' => 'Select an authorized branch belonging to your company.',
    'default_branch_hint' => 'For a company Super Admin, this is a default branch. Access remains available to every branch and company-wide record in the same company.',
    'posting_help' => 'Stock movements and payments are posted on the recording date.',
];
