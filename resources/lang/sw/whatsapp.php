<?php

return [
    'months' => [
        'short' => [
            1 => 'Jan',
            2 => 'Feb',
            3 => 'Mac',
            4 => 'Apr',
            5 => 'Mei',
            6 => 'Jun',
            7 => 'Jul',
            8 => 'Ago',
            9 => 'Sep',
            10 => 'Okt',
            11 => 'Nov',
            12 => 'Des',
        ],
    ],
    'common' => [
        'branch' => 'Tawi',
        'scope' => 'Eneo',
        'all_authorized_locations' => 'Maeneo yote yaliyoruhusiwa',
        'own_activity' => 'Shughuli zako — :name',
        'customer' => 'Mteja',
        'walk_in_customer' => 'Mteja wa Moja kwa Moja',
        'payment_methods' => [
            'cash' => 'Taslimu',
            'credit' => 'Mkopo',
            'bank' => 'Benki',
            'mobile_money' => 'Pesa ya Simu',
            'card' => 'Kadi',
        ],
        'system' => 'Mfumo',
    ],
    'daily_summary' => [
        'title' => 'MUHTASARI WA SIKU - HARDEX',
        'sales' => 'Mauzo',
        'transactions' => 'Idadi ya Miamala',
        'cash_sales' => 'Mauzo ya Taslimu',
        'credit_sales' => 'Mauzo ya Mkopo',
        'payments_received' => 'Malipo Yaliyopokelewa',
        'top_product' => 'Bidhaa Iliyouzwa Zaidi',
        'low_stock' => 'Hisa Ndogo',
        'out_of_stock' => 'Zilizoisha Hisa',
        'cogs' => 'Gharama ya Bidhaa Zilizouzwa',
        'gross_profit' => 'Faida Ghafi',
        'expenses' => 'Matumizi',
        'net_profit' => 'Faida Halisi',
        'purchase_orders_today' => 'MAAGIZO YA UNUNUZI YA LEO',
        'goods_received_today' => 'BIDHAA ZILIZOPOKELEWA LEO',
        'purchase_orders' => 'Maagizo ya Ununuzi',
        'ordered_value' => 'Thamani ya Maagizo',
        'grns' => 'HATI YA UPOKEAJI',
        'goods_value' => 'Thamani ya Bidhaa',
        'additional_costs' => 'Gharama za Ziada',
        'landed_value' => 'Thamani ya Kufikisha bidhaa',
        'profit_margin' => 'Asilimia ya Faida',
        'stock_value' => 'Thamani ya Hisa',
    ],
    'stock_alert' => [
        'title' => 'TAARIFA YA HISA - HARDEX',
        'items_attention' => 'Bidhaa :count zinahitaji uangalizi.',
        'out_of_stock' => 'Zilizoisha Hisa',
        'low_stock' => 'Hisa Ndogo',
        'generated' => 'Imetengenezwa',
        'attachment_note' => 'Ripoti kamili ya bidhaa zenye hisa ndogo au zilizoisha imeambatanishwa.',
        'attachment_failed' => 'Ripoti kamili haikuweza kuambatanishwa; fungua HARDEX POS kwa maelezo.',
        'attachment_disabled' => 'Kiambatisho cha PDF kimezimwa; fungua HARDEX POS kwa maelezo.',
    ],
    'sale_completed' => [
        'title' => 'MAUZO MAPYA',
        'invoice' => 'Ankara',
        'date' => 'Tarehe',
        'branch' => 'Tawi',
        'sold_by' => 'Muuzaji',
        'customer' => 'Mteja',
        'products' => 'Bidhaa',
        'total_quantity' => 'Jumla ya Bidhaa',
        'total' => 'Jumla',
        'paid' => 'Iliyolipwa',
        'balance' => 'Salio',
        'payment' => 'Malipo',
        'more_items' => '...na bidhaa nyingine :count',
        'recorded_at' => 'Imeingizwa tarehe',
        'notification_date' => 'Taarifa imeandaliwa tarehe',
    ],
    'material_issue' => [
        'title' => 'BIDHAA ZIMETOLEWA - HARDEX',
        'reference' => 'Kumbukumbu',
        'date' => 'Tarehe',
        'customer' => 'Mteja',
        'materials' => 'Bidhaa',
        'total_value' => 'Jumla ya Bidhaa',
        'previous_balance' => 'Salio la Awali',
        'amount_used' => 'Thamani Iliyotumika',
        'new_balance' => 'Salio Jipya',
        'issued_by' => 'Iliyotolewa na',
        'collected_by' => 'Aliyechukua',
        'attachment' => 'Risiti ya utoaji bidhaa :reference imeambatanishwa.',
    ],
    'templates' => [
        'sale_completed' => '*MAUZO MAPYA*

Ankara: {{sale_number}}
Tarehe: {{date}}
Tawi: {{branch}}
Muuzaji: {{cashier}}
Mteja: {{customer}}

Bidhaa:
{{products}}

Jumla ya Bidhaa: {{total_quantity}}
Jumla: {{currency}} {{total}}
Iliyolipwa: {{currency}} {{paid}}
Salio: {{currency}} {{balance}}

Malipo: {{payment}}

HARDEX POS',
        'sale_cancelled' => 'TAHADHARI YA USALAMA - HARDEX
Mauzo {{sale_number}} yamefutwa.
Tawi: {{branch}}
Kiasi: TZS {{amount}}
Yamefutwa na: {{actor}}
Muda: {{time}}',
        'customer_payment_received' => 'MALIPO YA MTEJA - HARDEX
Malipo yamepokelewa kutoka kwa {{customer}}.
Kiasi: TZS {{amount}}
Kumbukumbu: {{reference}}
Tawi: {{branch}}
Yamepokelewa na: {{actor}}',
        'purchase_order_created' => '*AGIZO LA UNUNUZI LIMEUNDWA*

Namba ya AGIZO LA UNUNUZI: {{po_number}}
Msambazaji: {{supplier}}
Tawi: {{branch}}
Aliyeunda: {{created_by}}
Tarehe: {{date}}

*BIDHAA ZILIZOAGIZWA*
{{products}}

*MUHTASARI*
Bidhaa: {{products_count}}
Thamani ya Agizo: TZS {{order_value}}

{{company_name}}',
        'goods_received_grn' => '*BIDHAA ZIMEPOKELEWA / HATI YA UPOKEAJI*

Namba ya HATI YA UPOKEAJI: {{grn_number}}
Namba ya AGIZO LA UNUNUZI: {{po_number}}
Msambazaji: {{supplier}}
Zimepokelewa: {{receiving_location}}
Aliyepokea: {{received_by}}
Tarehe: {{date}}

*BIDHAA ZILIZOPOKELEWA*
{{products}}

{{additional_costs}}
*MUHTASARI*
Bidhaa: {{products_count}}
Jumla ya Kiasi: {{total_received_quantity}}
Thamani ya Bidhaa: TZS {{goods_value}}
Gharama za Ziada: TZS {{additional_cost_total}}
Thamani ya Kufikisha bidhaa: TZS {{landed_value}}

{{company_name}}',
        'production_completed' => 'UZALISHAJI UMEKAMILIKA - HARDEX
Oda: {{order_number}}
Bidhaa: {{product}}
Zilizokubaliwa: {{accepted}}
Zilizokataliwa: {{rejected}}
Tawi: {{branch}}',
    ],
    'purchase_notifications' => [
        'qty' => 'Kiasi',
        'ordered_qty' => 'Kiasi Kilichoagizwa',
        'received_this_grn' => 'Kilichopokelewa HATI YA UPOKEAJI Hii',
        'previously_received' => 'Kilichopokelewa Kabla',
        'remaining' => 'Kilichobaki',
        'conversion' => 'Ubadilishaji',
        'unit_price' => 'Bei ya Kimoja',
        'total' => 'Jumla',
        'purchase_cost_unit' => 'Gharama ya Ununuzi/Kimoja',
        'goods_value' => 'Thamani ya Bidhaa',
        'allocated_cost' => 'Gharama ya Ziada Iliyogawiwa',
        'landed_cost_unit' => 'Kufikisha bidhaa Gharama/Kimoja',
        'landed_value' => 'Thamani ya Kufikisha bidhaa',
        'additional_costs' => 'GHARAMA ZA ZIADA',
    ],
    'debt' => [
        'management_title' => 'TAARIFA YA MADENI - HARDEX',
        'due_tomorrow' => 'YANAYOTAKIWA KESHO',
        'due_today' => 'YANAYOTAKIWA LEO',
        'overdue' => 'YALIYOCHELEWA',
        'customers' => 'Wateja',
        'total' => 'Jumla',
        'highest_outstanding' => 'Madeni Makubwa Zaidi',
        'open_report' => 'Fungua HARDEX kuona ripoti kamili ya wadaiwa.',
        'greeting' => 'Habari :name,',
        'reminder_from' => 'Kumbusho kutoka :company.',
        'overdue_body' => 'Deni lako la TZS :amount lilipaswa kulipwa tarehe :date na sasa limechelewa kwa siku :days.',
        'due_body' => 'Una deni la TZS :amount linalotarajiwa kulipwa tarehe :date.',
        'reference' => 'Kumbukumbu',
        'contact' => 'Tafadhali wasiliana nasi kwa maelezo zaidi.',
        'paid_contact' => 'Kama tayari umelipa, tafadhali wasiliana nasi.',
        'thanks' => 'Asante.',
    ],
    'operational' => [
        'goods_received_title' => 'BIDHAA ZIMEPOKELEWA - HARDEX',
        'supplier' => 'Msambazaji',
        'branch' => 'Tawi',
        'received' => 'Zimepokelewa',
        'material_deposit_title' => 'AMANA YA VIFAA - HARDEX',
        'account' => 'Akaunti',
        'customer' => 'Mteja',
        'reference' => 'Kumbukumbu',
        'amount' => 'Kiasi',
        'material_issue_title' => 'UTOAJI WA VIFAA - HARDEX',
        'value' => 'Thamani',
        'curing_release_title' => 'UTOAJI WA BIDHAA ZILIZOKAUKA - HARDEX',
        'curing_damage_title' => 'UHARIBIFU WA BIDHAA ZINAZOKAUKA - HARDEX',
        'batch' => 'Kundi',
        'product' => 'Bidhaa',
        'quantity' => 'Kiasi',
        'destination' => 'Zinakopelekwa',
        'reason' => 'Sababu',
    ],
    'b2b' => [
        'request_title' => 'OMBI JIPYA LA MTEJA',
        'request' => 'Ombi',
        'customer' => 'Mteja',
        'branch' => 'Tawi',
        'items' => 'Bidhaa',
        'estimated_value' => 'Thamani Iliyokadiriwa',
        'request_submitted' => 'Ombi jipya la ununuzi limetumwa kupitia HARDEX Mteja Tovuti.',
        'review_request' => 'Fungua HARDEX kukagua na kuandaa nukuu ya bei.',
        'quotation_accepted_title' => 'NUKUU YA BEI IMEKUBALIWA',
        'quotation' => 'Nukuu ya bei',
        'amount' => 'Kiasi',
        'accepted_note' => 'Mteja amekubali nukuu ya bei kupitia HARDEX Mteja Tovuti.',
        'hello' => 'Habari :name,',
        'quotation_ready' => ':document yako :reference kutoka :company imeandaliwa.',
        'total' => 'Jumla',
        'valid_until' => 'Inatumika Hadi',
        'pdf_attached' => 'PDF imeambatanishwa.',
        'thanks' => 'Asante.',
        'quotation_document' => 'Nukuu ya bei',
        'proforma_document' => 'Ankara ya awali Ankara',
        'sale_complete' => 'Mauzo yako :reference yamekamilika.',
        'paid' => 'Malipo',
        'balance' => 'Salio',
        'invoice_attached' => 'Ankara imeambatanishwa.',
        'purchase_thanks' => 'Asante kwa kununua :company.',
    ],
    'portal' => [
        'title' => 'HARDEX — TOVUTI YA WATEJA',
        'hello' => 'Habari :name,',
        'registered' => 'Karibu! Umesajiliwa kama mteja wa :company. Tunafurahi kukuhudumia.',
        'login_intro' => 'Ingia kwenye tovuti yetu kwa kutumia namba yako ya simu na PIN ya muda iliyo hapa chini:',
        'phone' => 'Namba ya Simu',
        'temporary_password' => 'PIN ya Kuingia (tarakimu 4)',
        'security' => 'PIN hii inatumika mara moja tu na inaisha baada ya dakika 15. Ukiingia utafunguliwa kwenye Dashibodi moja kwa moja bila kulazimishwa kubadilisha nenosiri. Kubadilisha nenosiri ni hiari kupitia Akaunti. Ukitoka kwenye akaunti bila kuweka nenosiri la kudumu, tumia Umesahau Nenosiri kupata PIN mpya. Usimshirikishe mtu mwingine PIN yako.',
        'features_intro' => 'Kupitia tovuti unaweza:',
        'features' => [
            0 => 'Kutafuta bidhaa na kutuma maombi ya nukuu za bei au kuagiza bidhaa',
            1 => 'Kuangalia na kukubali au kukataa nukuu za bei',
            2 => 'Kuangalia ankara, madeni na historia ya malipo',
            3 => 'Kutuma uthibitisho wa malipo na kuangalia taarifa ya akaunti',
            4 => 'Kuomba kuletewa bidhaa',
        ],
        'thanks' => 'Asante kwa kutumia :company.',
        'phone_changed' => 'Namba yako ya kuingia Mteja Tovuti imebadilishwa kuwa :phone. Nenosiri yako haijabadilishwa.',
        'url_label' => 'Tovuti ya Wateja',
    ],
    'test' => [
        'title' => 'Jaribio la WhatsApp la HARDEX',
    ],
    'product_deleted' => [
        'body' => '*BIDHAA IMEFUTWA*

Bidhaa: :product
Msimbo wa Bidhaa: :sku
Tawi: :branch
Amefuta: :actor
Tarehe: :date

Bidhaa hii imeondolewa kwenye orodha ya bidhaa zinazotumika.

:company',
    ],
];
