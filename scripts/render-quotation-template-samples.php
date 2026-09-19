<?php

use App\Models\Company;
use App\Services\QuotationDocumentService;
use App\Support\QuotationTemplateRegistry;
use Illuminate\Contracts\Console\Kernel;

// Presentation-only sample generator. Does not connect to the database or save models.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = $argv[1] ?? sys_get_temp_dir().'/hardex-quotation-samples';
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
$company = new Company([
    'company_name' => 'HARDEX DEMO', 'address' => 'Hardware & building supplies · Sample company',
    'phone' => '+255 000 000 000', 'email' => 'demo@example.test',
]);
$documents = app(QuotationDocumentService::class);
foreach (QuotationTemplateRegistry::all() as $key => $template) {
    foreach ([1, 10, 35] as $count) {
        $document = $documents->sample($company, $count);
        $document['items'][0]['product'] = 'DANGOTE CEMENT 32.5N PREMIUM GENERAL PURPOSE CEMENT 50KG';
        $document['payments'] = [[
            'display_name' => 'DEMO PAYMENT METHOD', 'account_name' => 'Sample company',
            'account_number' => 'SAMPLE-ACCOUNT', 'instructions' => 'Preview only. Use configured company payment details on real quotations.',
        ]];
        file_put_contents($directory.'/'.$key.'-'.$count.'.pdf', $documents->pdf($document, $key));
    }
    if (in_array($key, ['classic', 'compact', 'premium'], true)) {
        $plain = $documents->sample($company, 1);
        file_put_contents($directory.'/'.$key.'-no-payments.pdf', $documents->pdf($plain, $key));
        $logo = imagecreatetruecolor(2400, 600);
        imagefill($logo, 0, 0, imagecolorallocate($logo, 35, 62, 80));
        imagestring($logo, 5, 40, 40, 'HARDEX LOGO SIZE TEST', imagecolorallocate($logo, 255, 255, 255));
        ob_start();
        imagepng($logo);
        $plain['company']['logo'] = 'data:image/png;base64,'.base64_encode(ob_get_clean());
        file_put_contents($directory.'/'.$key.'-large-logo.pdf', $documents->pdf($plain, $key));
        imagedestroy($logo);
    }
    echo $template['name']." samples generated.\n";
}
