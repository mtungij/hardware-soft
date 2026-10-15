<?php

return [
    'months' => [
        'short' => [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'May',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Aug',
            9 => 'Sep',
            10 => 'Oct',
            11 => 'Nov',
            12 => 'Dec',
        ],
    ],
    'common' => [
        'branch' => 'Branch',
        'scope' => 'Scope',
        'all_authorized_locations' => 'All authorized locations',
        'own_activity' => 'Own activity — :name',
        'customer' => 'Customer',
        'walk_in_customer' => 'Walk-in Customer',
        'payment_methods' => [
            'cash' => 'Cash',
            'credit' => 'Credit',
            'bank' => 'Bank',
            'mobile_money' => 'Mobile Money',
            'card' => 'Card',
        ],
        'system' => 'System',
    ],
    'daily_summary' => [
        'title' => 'HARDEX DAILY SUMMARY',
        'sales' => 'Sales',
        'transactions' => 'Transactions',
        'cash_sales' => 'Cash Sales',
        'credit_sales' => 'Credit Sales',
        'payments_received' => 'Payments Received',
        'top_product' => 'Top Product',
        'low_stock' => 'Low Stock',
        'out_of_stock' => 'Out of Stock',
        'cogs' => 'COGS',
        'gross_profit' => 'Gross Profit',
        'expenses' => 'Expenses',
        'net_profit' => 'Net Profit',
        'purchase_orders_today' => 'PURCHASE ORDERS CREATED TODAY',
        'goods_received_today' => 'GOODS RECEIVED TODAY',
        'purchase_orders' => 'Purchase Orders',
        'ordered_value' => 'Total Ordered Value',
        'grns' => 'GRNs',
        'goods_value' => 'Goods Value',
        'additional_costs' => 'Additional Costs',
        'landed_value' => 'Total Landed Value',
        'profit_margin' => 'Profit Margin',
        'stock_value' => 'Stock Value',
    ],
    'stock_alert' => [
        'title' => 'HARDEX STOCK ALERT',
        'items_attention' => ':count stock items require attention.',
        'out_of_stock' => 'Out of Stock',
        'low_stock' => 'Low Stock',
        'generated' => 'Generated',
        'attachment_note' => 'Full Low / Out of Stock report is attached.',
        'attachment_failed' => 'The full report could not be attached; view HARDEX POS for details.',
        'attachment_disabled' => 'PDF attachment is disabled; view HARDEX POS for details.',
    ],
    'sale_completed' => [
        'title' => 'NEW SALE',
        'invoice' => 'Invoice',
        'date' => 'Date',
        'branch' => 'Branch',
        'sold_by' => 'Sold By',
        'customer' => 'Customer',
        'products' => 'Products',
        'total_quantity' => 'Total Quantity',
        'total' => 'Total',
        'paid' => 'Paid',
        'balance' => 'Balance',
        'payment' => 'Payment',
        'more_items' => '...and :count more items',
        'recorded_at' => 'Recorded at',
        'notification_date' => 'Notification prepared at',
    ],
    'material_issue' => [
        'title' => 'MATERIALS ISSUED - HARDEX',
        'reference' => 'Reference',
        'date' => 'Date',
        'customer' => 'Customer',
        'materials' => 'Materials',
        'total_value' => 'Total Material Value',
        'previous_balance' => 'Previous Balance',
        'amount_used' => 'Amount Used',
        'new_balance' => 'New Balance',
        'issued_by' => 'Issued By',
        'collected_by' => 'Collected By',
        'attachment' => 'Material issue receipt :reference is attached.',
    ],
    'templates' => [
        'sale_completed' => '*NEW SALE*

Invoice: {{sale_number}}
Date: {{date}}
Branch: {{branch}}
Sold By: {{cashier}}
Customer: {{customer}}

Products:
{{products}}

Total Quantity: {{total_quantity}}
Total: {{currency}} {{total}}
Paid: {{currency}} {{paid}}
Balance: {{currency}} {{balance}}

Payment: {{payment}}

HARDEX POS',
        'sale_cancelled' => 'HARDEX SECURITY ALERT
Sale {{sale_number}} was cancelled.
Branch: {{branch}}
Amount: TZS {{amount}}
Cancelled by: {{actor}}
Time: {{time}}',
        'customer_payment_received' => 'HARDEX CUSTOMER PAYMENT
Payment received from {{customer}}.
Amount: TZS {{amount}}
Reference: {{reference}}
Branch: {{branch}}
Received by: {{actor}}',
        'purchase_order_created' => '*PURCHASE ORDER CREATED*

PO No: {{po_number}}
Supplier: {{supplier}}
Branch: {{branch}}
Created By: {{created_by}}
Date: {{date}}

*PRODUCTS ORDERED*
{{products}}

*SUMMARY*
Products: {{products_count}}
Total Order Value: TZS {{order_value}}

{{company_name}}',
        'goods_received_grn' => '*GOODS RECEIVED / GRN*

GRN No: {{grn_number}}
PO No: {{po_number}}
Supplier: {{supplier}}
Received At: {{receiving_location}}
Received By: {{received_by}}
Date: {{date}}

*PRODUCTS RECEIVED*
{{products}}

{{additional_costs}}
*SUMMARY*
Products: {{products_count}}
Total Received Qty: {{total_received_quantity}}
Goods Value: TZS {{goods_value}}
Total Additional Costs: TZS {{additional_cost_total}}
Total Landed Value: TZS {{landed_value}}

{{company_name}}',
        'production_completed' => 'HARDEX PRODUCTION COMPLETED
Order: {{order_number}}
Product: {{product}}
Accepted: {{accepted}}
Rejected: {{rejected}}
Branch: {{branch}}',
    ],
    'purchase_notifications' => [
        'qty' => 'Qty',
        'ordered_qty' => 'Ordered Qty',
        'received_this_grn' => 'Received This GRN',
        'previously_received' => 'Previously Received',
        'remaining' => 'Remaining',
        'conversion' => 'Conversion',
        'unit_price' => 'Unit Price',
        'total' => 'Total',
        'purchase_cost_unit' => 'Purchase Cost/Unit',
        'goods_value' => 'Goods Value',
        'allocated_cost' => 'Allocated Additional Cost',
        'landed_cost_unit' => 'Landed Cost/Unit',
        'landed_value' => 'Landed Value',
        'additional_costs' => 'ADDITIONAL COSTS',
    ],
    'debt' => [
        'management_title' => 'HARDEX CREDIT ALERT',
        'due_tomorrow' => 'DUE TOMORROW',
        'due_today' => 'DUE TODAY',
        'overdue' => 'OVERDUE',
        'customers' => 'Customers',
        'total' => 'Total',
        'highest_outstanding' => 'Highest Outstanding',
        'open_report' => 'Open HARDEX for the complete debtor report.',
        'greeting' => 'Hello :name,',
        'reminder_from' => 'A reminder from :company.',
        'overdue_body' => 'Your TZS :amount balance was due on :date and is now :days day(s) overdue.',
        'due_body' => 'You have a TZS :amount balance due on :date.',
        'reference' => 'Reference',
        'contact' => 'Please contact us for more information.',
        'paid_contact' => 'If you have already paid, please contact us.',
        'thanks' => 'Thank you.',
    ],
    'operational' => [
        'goods_received_title' => 'HARDEX GOODS RECEIVED',
        'supplier' => 'Supplier',
        'branch' => 'Branch',
        'received' => 'Received',
        'material_deposit_title' => 'HARDEX MATERIAL DEPOSIT',
        'account' => 'Account',
        'customer' => 'Customer',
        'reference' => 'Reference',
        'amount' => 'Amount',
        'material_issue_title' => 'HARDEX MATERIAL ISSUE',
        'value' => 'Value',
        'curing_release_title' => 'HARDEX CURING RELEASE',
        'curing_damage_title' => 'HARDEX CURING DAMAGE',
        'batch' => 'Batch',
        'product' => 'Product',
        'quantity' => 'Quantity',
        'destination' => 'Destination',
        'reason' => 'Reason',
    ],
    'b2b' => [
        'request_title' => 'NEW CUSTOMER REQUEST',
        'request' => 'Request',
        'customer' => 'Customer',
        'branch' => 'Branch',
        'items' => 'Items',
        'estimated_value' => 'Estimated Value',
        'request_submitted' => 'A new purchase request was submitted through the HARDEX Customer Portal.',
        'review_request' => 'Open HARDEX to review and prepare a quotation.',
        'quotation_accepted_title' => 'QUOTATION ACCEPTED',
        'quotation' => 'Quotation',
        'amount' => 'Amount',
        'accepted_note' => 'The customer accepted the quotation through the HARDEX Customer Portal.',
        'hello' => 'Hello :name,',
        'quotation_ready' => 'Your :document :reference from :company is ready.',
        'total' => 'Total',
        'valid_until' => 'Valid Until',
        'pdf_attached' => 'The PDF is attached.',
        'thanks' => 'Thank you.',
        'quotation_document' => 'Quotation',
        'proforma_document' => 'Proforma Invoice',
        'sale_complete' => 'Your sale :reference is complete.',
        'paid' => 'Paid',
        'balance' => 'Balance',
        'invoice_attached' => 'The invoice is attached.',
        'purchase_thanks' => 'Thank you for shopping with :company.',
    ],
    'portal' => [
        'title' => 'HARDEX CUSTOMER PORTAL',
        'hello' => 'Hello :name,',
        'registered' => 'You have been registered as a customer of :company.',
        'login_intro' => 'You can sign in to the Customer Portal using the details below:',
        'phone' => 'Phone Number',
        'temporary_password' => 'Login PIN (4 digits)',
        'security' => 'This PIN can only be used once and expires after 15 minutes. You will go directly to your dashboard without being required to change your password. Changing your password is optional under Account. If you log out without setting a permanent password, use Forgot Password to request a new PIN. Never share your PIN.',
        'features_intro' => 'Through the portal you can:',
        'features' => [
            0 => 'Search products and request quotations or orders',
            1 => 'View, accept or decline quotations',
            2 => 'View invoices, debts and payment history',
            3 => 'Submit payment receipts and view account statements',
            4 => 'Request delivery',
        ],
        'thanks' => 'Thank you for using :company.',
        'phone_changed' => 'Your Customer Portal login number has changed to :phone. Your password has not changed.',
        'url_label' => 'Customer Portal',
    ],
    'test' => [
        'title' => 'HARDEX WhatsApp test',
    ],
    'product_deleted' => [
        'body' => '*PRODUCT DELETED*

Product: :product
SKU: :sku
Branch: :branch
Deleted By: :actor
Date: :date

The product has been removed from active inventory.

:company',
    ],
];
