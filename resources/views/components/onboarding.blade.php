@props([
    'context' => 'staff',
    'role' => 'User',
    'userKey' => 'guest',
])

@php
    $isCustomer = $context === 'customer';
    $staffTours = [
        'dashboard' => [
            ['target' => '[data-tour="dashboard-overview"]', 'title' => __('onboarding.copy.541a5bc1d83d'), 'body' => __('onboarding.copy.36e9d4cf53de')],
            ['target' => '[data-tour="dashboard-stats"]', 'title' => __('onboarding.copy.7472a06a12ce'), 'body' => __('onboarding.copy.93acca8e7c76')],
            ['target' => '[data-tour="dashboard-charts"]', 'title' => __('onboarding.copy.c873be3f54a6'), 'body' => __('onboarding.copy.ed22a747ccef')],
            ['target' => '[data-tour="notifications"]', 'title' => __('onboarding.copy.0e39dac2a890'), 'body' => __('onboarding.copy.37355f7432b9')],
            ['target' => '[data-tour="help-center"]', 'title' => __('onboarding.copy.10877caeba8e'), 'body' => __('onboarding.copy.342d56477fdb')],
            ['target' => '[data-tour="profile-menu"]', 'title' => __('onboarding.copy.e32707613d1f'), 'body' => __('onboarding.copy.64535b415b50')],
            ['target' => '[data-tour="theme-switcher"]', 'title' => __('onboarding.copy.50c8cf6e45ab'), 'body' => __('onboarding.copy.f9f7d89bcd86')],
            ['target' => '[data-tour="language-switcher"]', 'title' => __('onboarding.copy.ad767c66efd5'), 'body' => __('onboarding.copy.8cb8a73f7bb9')],
        ],
        'products' => [
            ['target' => '[data-tour="products-list"]', 'title' => __('onboarding.copy.b9770d4992a0'), 'body' => __('onboarding.copy.1e5afdd11aa9')],
            ['target' => '[data-tour="add-product"]', 'title' => __('onboarding.copy.ee611e536d6e'), 'body' => __('onboarding.copy.11b01641da9e')],
            ['target' => '[data-tour="product-search"]', 'title' => __('onboarding.copy.0a11bfbb7da0'), 'body' => __('onboarding.copy.1fecd4cb7122')],
            ['target' => '[data-tour="product-filters"]', 'title' => __('onboarding.copy.96e578211aa2'), 'body' => __('onboarding.copy.125e8e3cbda9')],
            ['target' => '[data-tour="product-actions"]', 'title' => __('onboarding.copy.c3cd636a585b'), 'body' => __('onboarding.copy.661c0b13a3de')],
        ],
        'purchases' => [
            ['target' => '[data-tour="create-purchase"]', 'title' => __('onboarding.copy.3aa71f26d3d0'), 'body' => __('onboarding.copy.daa80c1f3a1e')],
            ['target' => '[data-tour="supplier-selection"]', 'title' => __('onboarding.copy.d5b044d03eeb'), 'body' => __('onboarding.copy.8b8470fcbe45')],
            ['target' => '[data-tour="purchase-products"]', 'title' => __('onboarding.copy.ee611e536d6e'), 'body' => __('onboarding.copy.790f78cbb767')],
            ['target' => '[data-tour="send-po"]', 'title' => __('onboarding.copy.15abad2e3a4c'), 'body' => __('onboarding.copy.6ba6e8bd1069')],
            ['target' => '[data-tour="receive-stock"]', 'title' => __('onboarding.copy.7f38105bfda7'), 'body' => __('onboarding.copy.549844b75dc3')],
        ],
        'stock' => [
            ['target' => '[data-tour="main-store-stock"]', 'title' => __('onboarding.copy.4c9fa476c226'), 'body' => __('onboarding.copy.4056dbba05bb')],
            ['target' => '[data-tour="dispensing-stock"]', 'title' => __('onboarding.copy.80a461ef9c3a'), 'body' => __('onboarding.copy.1eeeaedd957d')],
            ['target' => '[data-tour="stock-transfer"]', 'title' => __('onboarding.copy.ee8c274897d7'), 'body' => __('onboarding.copy.2fd5074f03de')],
            ['target' => '[data-tour="stock-history"]', 'title' => __('onboarding.copy.90cab686df19'), 'body' => __('onboarding.copy.5b7f5498661d')],
        ],
        'pos' => [
            ['target' => '[data-tour="pos-search"]', 'title' => __('onboarding.copy.7241cced24db'), 'body' => __('onboarding.copy.95cae71530b5')],
            ['target' => '[data-tour="barcode-input"]', 'title' => __('onboarding.copy.4efe43226832'), 'body' => __('onboarding.copy.b3b443471289')],
            ['target' => '[data-tour="pos-cart"]', 'title' => __('onboarding.copy.44656820495b'), 'body' => __('onboarding.copy.ed66538cf30f')],
            ['target' => '[data-tour="customer-selection"]', 'title' => __('onboarding.copy.80b02e00ec22'), 'body' => __('onboarding.copy.b4622303a280')],
            ['target' => '[data-tour="payment-methods"]', 'title' => __('onboarding.copy.f383f6a23c87'), 'body' => __('onboarding.copy.896f1a74926c')],
        ],
        'customers' => [
            ['target' => '[data-tour="customers-list"]', 'title' => __('onboarding.copy.d66465b53b59'), 'body' => __('onboarding.copy.f331edfc2532')],
            ['target' => '[data-tour="add-customer"]', 'title' => __('onboarding.copy.c92c0c39c1e8'), 'body' => __('onboarding.copy.e06d616caaf3')],
            ['target' => '[data-tour="credit-customers"]', 'title' => __('onboarding.copy.7d03e5c6c8db'), 'body' => __('onboarding.copy.35fdc5ddf518')],
            ['target' => '[data-tour="customer-payments"]', 'title' => __('onboarding.copy.6577ced3c078'), 'body' => __('onboarding.copy.532bd44319fe')],
            ['target' => '[data-tour="customer-statements"]', 'title' => __('onboarding.copy.b87a4bf19833'), 'body' => __('onboarding.copy.c87b67222db5')],
        ],
        'suppliers' => [
            ['target' => '[data-tour="suppliers-list"]', 'title' => __('onboarding.copy.ec59497d9a40'), 'body' => __('onboarding.copy.501cc8b0fd40')],
            ['target' => '[data-tour="add-supplier"]', 'title' => __('onboarding.copy.ae94295d1f5b'), 'body' => __('onboarding.copy.e341e8af4b0f')],
            ['target' => '[data-tour="supplier-history"]', 'title' => __('onboarding.copy.1bd2d1a15a43'), 'body' => __('onboarding.copy.886b09f4f925')],
            ['target' => '[data-tour="send-po"]', 'title' => __('onboarding.copy.a2234efca85f'), 'body' => __('onboarding.copy.9e06c683004e')],
        ],
        'reports' => [
            ['target' => '[data-tour="sales-reports"]', 'title' => __('onboarding.copy.3fcc78dda165'), 'body' => __('onboarding.copy.c17d4e77c7c6')],
            ['target' => '[data-tour="purchase-reports"]', 'title' => __('onboarding.copy.22ca67dcab9c'), 'body' => __('onboarding.copy.4519e687c743')],
            ['target' => '[data-tour="profit-reports"]', 'title' => __('onboarding.copy.d8447ea359bd'), 'body' => __('onboarding.copy.9c4458a01244')],
            ['target' => '[data-tour="stock-reports"]', 'title' => __('onboarding.copy.f0534f0d3623'), 'body' => __('onboarding.copy.a2077107f709')],
        ],
    ];
    $customerTours = [
        'customer-portal' => [
            ['target' => '[data-tour="customer-dashboard"]', 'title' => __('onboarding.copy.0f9c27e8c6e9'), 'body' => __('onboarding.copy.c9f53e1a26b2')],
            ['target' => '[data-tour="customer-debts"]', 'title' => __('onboarding.copy.f133ad28d636'), 'body' => __('onboarding.copy.300b4078d446')],
            ['target' => '[data-tour="upload-receipt"]', 'title' => __('onboarding.copy.9f344dadce0f'), 'body' => __('onboarding.copy.99ae8558d1e1')],
            ['target' => '[data-tour="customer-deposits"]', 'title' => __('onboarding.copy.5973e69df5bb'), 'body' => __('onboarding.copy.f60d48b17e8e')],
            ['target' => '[data-tour="customer-statements"]', 'title' => __('onboarding.copy.b87a4bf19833'), 'body' => __('onboarding.copy.054a0b1f4d8c')],
            ['target' => '[data-tour="customer-notifications"]', 'title' => __('onboarding.copy.753a22b2eb61'), 'body' => __('onboarding.copy.aa4c31b6cf86')],
            ['target' => '[data-tour="customer-profile"]', 'title' => __('onboarding.copy.ff4fc0276e96'), 'body' => __('onboarding.copy.bd8e9bd1cda6')],
        ],
    ];
    $checklist = $isCustomer
        ? [
            ['key' => 'view_dashboard', 'label' => __('onboarding.copy.468394394607')],
            ['key' => 'check_debts', 'label' => __('onboarding.copy.1c77b4216365')],
            ['key' => 'upload_receipt', 'label' => __('onboarding.copy.61b90f0dfedc')],
            ['key' => 'review_deposits', 'label' => __('onboarding.copy.47fb3dc15cba')],
            ['key' => 'download_statement', 'label' => __('onboarding.copy.69b1ba20b164')],
            ['key' => 'read_notifications', 'label' => __('onboarding.copy.6eb02cc372af')],
            ['key' => 'update_profile', 'label' => __('onboarding.copy.837b6de9dbcf')],
        ]
        : [
            ['key' => 'company', 'label' => __('onboarding.copy.69881a035a4b')],
            ['key' => 'branch', 'label' => __('onboarding.copy.994a56b1a09d')],
            ['key' => 'product', 'label' => __('onboarding.copy.44050c4435f0')],
            ['key' => 'supplier', 'label' => __('onboarding.copy.a72ba93918ba')],
            ['key' => 'purchase', 'label' => __('onboarding.copy.b7cf4d955ab0')],
            ['key' => 'receive_stock', 'label' => __('onboarding.copy.f53d92872005')],
            ['key' => 'sale', 'label' => __('onboarding.copy.84bc0e55a2ea')],
            ['key' => 'invite_staff', 'label' => __('onboarding.copy.cad4cf871c85')],
            ['key' => 'customer_portal', 'label' => __('onboarding.copy.edebb23aedd5')],
        ];
    $payload = [
        'context' => $context,
        'role' => \App\Support\UiText::translate($role),
        'userKey' => $userKey,
        'progressUrl' => route('onboarding.progress'),
        'csrf' => csrf_token(),
        'defaultTour' => $isCustomer ? 'customer-portal' : 'dashboard',
        'tours' => $isCustomer ? $customerTours : $staffTours,
        'checklist' => $checklist,
        'showWelcome' => false,
        'welcome' => [
            'title' => $isCustomer ? __('mail.portal.welcome', ['company' => 'Hardex']) : __('mail.portal.welcome', ['company' => 'Hardex ERP']),
            'message' => $isCustomer ? __('onboarding.welcome_message') : __('onboarding.welcome_message'),
        ],
    ];
@endphp

<div data-hardex-onboarding='@json($payload)'></div>
