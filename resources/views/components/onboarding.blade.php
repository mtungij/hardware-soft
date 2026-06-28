@props([
    'context' => 'staff',
    'role' => 'User',
    'userKey' => 'guest',
])

@php
    $isCustomer = $context === 'customer';
    $staffTours = [
        'dashboard' => [
            ['target' => '[data-tour="dashboard-overview"]', 'title' => 'Karibu kwenye Dashibodi', 'body' => 'Hii ndiyo sehemu kuu ya kufuatilia mauzo, manunuzi, faida, na hali ya biashara yako.'],
            ['target' => '[data-tour="dashboard-stats"]', 'title' => 'Kadi za Takwimu', 'body' => 'Hapa utaona takwimu muhimu kama mauzo ya leo, faida, bidhaa zilizobaki, na madeni.'],
            ['target' => '[data-tour="dashboard-charts"]', 'title' => 'Grafu za Biashara', 'body' => 'Grafu hizi zinakusaidia kufuatilia mwenendo wa mauzo na manunuzi.'],
            ['target' => '[data-tour="notifications"]', 'title' => 'Taarifa Muhimu', 'body' => 'Mfumo utakupa taarifa kuhusu bidhaa zinazokaribia kuisha, madeni, na shughuli muhimu.'],
            ['target' => '[data-tour="help-center"]', 'title' => 'Utafutaji na Msaada', 'body' => 'Tumia Kituo cha Msaada kutafuta bidhaa, wateja, manunuzi, ripoti, au kuanza mwongozo upya.'],
            ['target' => '[data-tour="profile-menu"]', 'title' => 'Mipangilio ya Akaunti', 'body' => 'Bonyeza hapa kusimamia akaunti yako na taarifa za mtumiaji.'],
            ['target' => '[data-tour="theme-switcher"]', 'title' => 'Mandhari ya Mfumo', 'body' => 'Badili kati ya Dark Mode na Light Mode.'],
            ['target' => '[data-tour="language-switcher"]', 'title' => 'Lugha ya Mfumo', 'body' => 'Badili lugha kati ya Kiswahili na English.'],
        ],
        'products' => [
            ['target' => '[data-tour="products-list"]', 'title' => 'Karibu kwenye Usimamizi wa Bidhaa', 'body' => 'Hii ni orodha ya bidhaa zote pamoja na bei na taarifa zake muhimu.'],
            ['target' => '[data-tour="add-product"]', 'title' => 'Ongeza Bidhaa', 'body' => 'Bonyeza hapa kuongeza bidhaa mpya.'],
            ['target' => '[data-tour="product-search"]', 'title' => 'Tafuta Bidhaa', 'body' => 'Tumia sehemu hii kutafuta bidhaa kwa jina, SKU, barcode, au brand.'],
            ['target' => '[data-tour="product-filters"]', 'title' => 'Vichujio', 'body' => 'Tumia vichujio kupata bidhaa kwa urahisi.'],
            ['target' => '[data-tour="product-actions"]', 'title' => 'Vitendo', 'body' => 'Bonyeza Vitendo kuona chaguo mbalimbali za bidhaa kama edit, activate, au delete.'],
        ],
        'purchases' => [
            ['target' => '[data-tour="create-purchase"]', 'title' => 'Karibu kwenye Manunuzi', 'body' => 'Unda oda mpya ya manunuzi unapohitaji kununua stock kutoka kwa supplier.'],
            ['target' => '[data-tour="supplier-selection"]', 'title' => 'Chagua Supplier', 'body' => 'Chagua supplier kabla ya kuongeza bidhaa unazonunua.'],
            ['target' => '[data-tour="purchase-products"]', 'title' => 'Ongeza Bidhaa', 'body' => 'Ongeza bidhaa, idadi, na gharama ya kununua.'],
            ['target' => '[data-tour="send-po"]', 'title' => 'Tuma Oda kwa Email', 'body' => 'Tuma oda kwa supplier kupitia email baada ya SMTP kusanidiwa.'],
            ['target' => '[data-tour="receive-stock"]', 'title' => 'Pokea Stock', 'body' => 'Pokea stock baada ya bidhaa kufika kutoka kwa supplier.'],
        ],
        'stock' => [
            ['target' => '[data-tour="main-store-stock"]', 'title' => 'Karibu kwenye Store Kuu', 'body' => 'Hii ndiyo stock kuu ya ghala kabla ya kuhamishwa kwenda Dispensing.'],
            ['target' => '[data-tour="dispensing-stock"]', 'title' => 'Dispensing Stock', 'body' => 'Hii ndiyo stock inayotumika kuuza moja kwa moja.'],
            ['target' => '[data-tour="stock-transfer"]', 'title' => 'Hamisha Stock', 'body' => 'Transfer stock kutoka Store Kuu kwenda Dispensing kabla ya kuuza.'],
            ['target' => '[data-tour="stock-history"]', 'title' => 'Historia ya Stock', 'body' => 'Fuatilia historia yote ya stock kwa usalama na uwazi.'],
        ],
        'pos' => [
            ['target' => '[data-tour="pos-search"]', 'title' => 'Karibu kwenye Mfumo wa Mauzo', 'body' => 'Tafuta bidhaa kwa haraka kwa jina, SKU, au barcode.'],
            ['target' => '[data-tour="barcode-input"]', 'title' => 'Barcode Scanner', 'body' => 'Tumia barcode scanner kuongeza kasi ya mauzo.'],
            ['target' => '[data-tour="pos-cart"]', 'title' => 'Kikapu', 'body' => 'Bidhaa zinaingia kwenye kikapu pamoja na idadi, punguzo, na jumla.'],
            ['target' => '[data-tour="customer-selection"]', 'title' => 'Chagua Mteja', 'body' => 'Chagua mteja kwa mauzo ya mkopo au kufuatilia akaunti.'],
            ['target' => '[data-tour="payment-methods"]', 'title' => 'Njia ya Malipo', 'body' => 'Chagua cash, mobile money, bank, au credit.'],
        ],
        'customers' => [
            ['target' => '[data-tour="customers-list"]', 'title' => 'Karibu kwenye Usimamizi wa Wateja', 'body' => 'Hapa utaona orodha ya wateja wote.'],
            ['target' => '[data-tour="add-customer"]', 'title' => 'Ongeza Mteja', 'body' => 'Ongeza mteja mpya na umtumie taarifa za kuingia Customer Portal.'],
            ['target' => '[data-tour="credit-customers"]', 'title' => 'Madeni ya Wateja', 'body' => 'Tazama madeni ya wateja na hali ya malipo.'],
            ['target' => '[data-tour="customer-payments"]', 'title' => 'Rekodi Malipo', 'body' => 'Rekodi malipo ili kupunguza salio la deni.'],
            ['target' => '[data-tour="customer-statements"]', 'title' => 'Taarifa za Akaunti', 'body' => 'Tengeneza na pakua taarifa za akaunti ya mteja.'],
        ],
        'suppliers' => [
            ['target' => '[data-tour="suppliers-list"]', 'title' => 'Karibu kwenye Suppliers', 'body' => 'Hapa utaona orodha ya suppliers wote.'],
            ['target' => '[data-tour="add-supplier"]', 'title' => 'Ongeza Supplier', 'body' => 'Ongeza supplier mpya kwa ajili ya manunuzi.'],
            ['target' => '[data-tour="supplier-history"]', 'title' => 'Historia ya Manunuzi', 'body' => 'Tazama historia ya manunuzi kutoka kwa supplier.'],
            ['target' => '[data-tour="send-po"]', 'title' => 'Tuma Oda', 'body' => 'Tuma oda kwa supplier kupitia email.'],
        ],
        'reports' => [
            ['target' => '[data-tour="sales-reports"]', 'title' => 'Karibu kwenye Ripoti', 'body' => 'Ripoti za Mauzo zinaonyesha mapato, cashiers, malipo, na wateja.'],
            ['target' => '[data-tour="purchase-reports"]', 'title' => 'Ripoti za Manunuzi', 'body' => 'Angalia manunuzi, suppliers, na balances.'],
            ['target' => '[data-tour="profit-reports"]', 'title' => 'Ripoti za Faida', 'body' => 'Fuatilia faida, gharama, na matokeo ya biashara.'],
            ['target' => '[data-tour="stock-reports"]', 'title' => 'Ripoti za Stock', 'body' => 'Chambua thamani ya stock na historia ya movement.'],
        ],
    ];
    $customerTours = [
        'customer-portal' => [
            ['target' => '[data-tour="customer-dashboard"]', 'title' => 'Karibu kwenye Portal ya Wateja', 'body' => 'Hapa utaona muhtasari wa akaunti yako.'],
            ['target' => '[data-tour="customer-debts"]', 'title' => 'Madeni Yangu', 'body' => 'Tazama madeni yote yaliyopo.'],
            ['target' => '[data-tour="upload-receipt"]', 'title' => 'Pakia Risiti', 'body' => 'Pakia uthibitisho wa malipo yako.'],
            ['target' => '[data-tour="customer-deposits"]', 'title' => 'Amana Zangu', 'body' => 'Tazama amana ulizoweka kwa matumizi ya baadaye.'],
            ['target' => '[data-tour="customer-statements"]', 'title' => 'Taarifa za Akaunti', 'body' => 'Pakua taarifa ya akaunti yako.'],
            ['target' => '[data-tour="customer-notifications"]', 'title' => 'Taarifa', 'body' => 'Pokea matangazo na ujumbe kutoka kampuni.'],
            ['target' => '[data-tour="customer-profile"]', 'title' => 'Wasifu', 'body' => 'Badili taarifa zako na lugha unayopendelea.'],
        ],
    ];
    $checklist = $isCustomer
        ? [
            ['key' => 'view_dashboard', 'label' => 'Angalia dashibodi'],
            ['key' => 'check_debts', 'label' => 'Angalia Madeni Yangu'],
            ['key' => 'upload_receipt', 'label' => 'Pakia risiti ya kwanza'],
            ['key' => 'review_deposits', 'label' => 'Angalia amana zako'],
            ['key' => 'download_statement', 'label' => 'Fungua taarifa ya akaunti'],
            ['key' => 'read_notifications', 'label' => 'Soma taarifa'],
            ['key' => 'update_profile', 'label' => 'Sasisha wasifu'],
        ]
        : [
            ['key' => 'company', 'label' => 'Jaza Taarifa za Kampuni'],
            ['key' => 'branch', 'label' => 'Ongeza Tawi la Kwanza'],
            ['key' => 'product', 'label' => 'Ongeza Bidhaa ya Kwanza'],
            ['key' => 'supplier', 'label' => 'Ongeza Supplier wa Kwanza'],
            ['key' => 'purchase', 'label' => 'Rekodi Manunuzi ya Kwanza'],
            ['key' => 'receive_stock', 'label' => 'Pokea Stock ya Kwanza'],
            ['key' => 'sale', 'label' => 'Fanya Mauzo ya Kwanza'],
            ['key' => 'invite_staff', 'label' => 'Ongeza Mtumiaji wa Kwanza'],
            ['key' => 'customer_portal', 'label' => 'Washa Portal ya Wateja'],
        ];
    $payload = [
        'context' => $context,
        'role' => $role,
        'userKey' => $userKey,
        'progressUrl' => route('onboarding.progress'),
        'csrf' => csrf_token(),
        'defaultTour' => $isCustomer ? 'customer-portal' : 'dashboard',
        'tours' => $isCustomer ? $customerTours : $staffTours,
        'checklist' => $checklist,
        'showWelcome' => false,
        'welcome' => [
            'title' => $isCustomer ? 'Karibu Portal ya Wateja' : 'Karibu Hardex ERP',
            'message' => $isCustomer ? 'Karibu kwenye Portal ya Wateja. Tutakuonyesha mwongozo mfupi wa kutumia mfumo huu kwa urahisi.' : "Karibu kwenye Hardex Hardware ERP.\n\nTutakuonyesha mwongozo mfupi wa jinsi ya kutumia mfumo huu kwa urahisi.",
        ],
    ];
@endphp

<div data-hardex-onboarding='@json($payload)'></div>
