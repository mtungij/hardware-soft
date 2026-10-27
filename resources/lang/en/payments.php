<?php

return [
    'title' => 'Payment Methods', 'payments' => 'Payments', 'description' => 'Configure the ways this company receives and pays money.',
    'display_name' => 'Method Name', 'type' => 'Type', 'account_name' => 'Account Name', 'account_number' => 'Account Number',
    'instructions' => 'Instructions', 'sort_order' => 'Display Order', 'branch_id' => 'Branch Availability', 'all_branches' => 'All Branches',
    'is_active' => 'Active', 'is_default' => 'Default Method', 'save' => 'Save Method', 'edit' => 'Edit', 'new' => 'New Method',
    'saved' => 'Payment method saved.', 'select' => 'Select payment method', 'unavailable' => 'Select an active payment method available to this company and branch.',
    'default_active' => 'The default method must be active.', 'positive_amount' => 'Payment amounts must be positive and finite.',
    'credit' => 'Credit — outstanding balance', 'all_methods' => 'All Payment Methods',
    'noncash_overpayment' => 'Non-cash payments cannot exceed the sale total.',
    'cash_change_only' => 'Only cash payments can generate change.',
    'invoice_overpayment' => 'Payment amount cannot exceed the outstanding invoice balance.',
    'types' => ['cash' => 'Cash', 'mobile_money' => 'Mobile Money', 'bank' => 'Bank Transfer', 'card' => 'Card', 'other' => 'Other'],
];
