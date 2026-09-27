<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Services\PwaBrandingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', 'admin@buildmart.test')->firstOrFail();
    $this->actingAs($this->admin);
    $this->company = Company::findOrFail($this->admin->company_id);
    $this->settings = Setting::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
});

function pwaTestLogo(): string
{
    $image = imagecreatetruecolor(16, 16);
    $color = imagecolorallocate($image, 20, 100, 220);
    imagefilledrectangle($image, 0, 0, 15, 15, $color);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

test('manifest and icons use the authenticated company name, colors, and logo', function () {
    Storage::fake('public');
    Storage::disk('public')->put('company-logos/bin.png', pwaTestLogo());
    $this->company->update(['company_name' => 'BIN HUSSEIN TRADERS', 'logo' => 'company-logos/bin.png']);
    $this->settings->update(['theme_color' => '#123456', 'pwa_background_color' => '#abcdef']);

    $manifest = $this->get(route('pwa.manifest'))->assertOk()
        ->assertHeader('Vary', 'Cookie, Host');
    expect($manifest->headers->get('Cache-Control'))->toContain('private', 'no-cache', 'must-revalidate');
    expect($manifest->headers->get('Content-Type'))->toStartWith('application/manifest+json');
    $body = $manifest->json();
    expect($body['name'])->toBe('BIN HUSSEIN TRADERS')
        ->and($body['short_name'])->toBe('BIN HUSSEIN TRADERS')
        ->and($body['theme_color'])->toBe('#123456')
        ->and($body['background_color'])->toBe('#abcdef')
        ->and($body['icons'])->toHaveCount(2)
        ->and($body['icons'][0]['sizes'])->toBe('192x192')
        ->and($body['icons'][1]['sizes'])->toBe('512x512')
        ->and($body['icons'][0]['purpose'])->toBe('any maskable')
        ->and($body['icons'][0]['src'])->toContain($body['version']);
    $icon = $this->get($body['icons'][0]['src'])->assertOk()->assertHeader('Content-Type', 'image/png');
    expect(getimagesizefromstring($icon->getContent())[0])->toBe(192)
        ->and(getimagesizefromstring($icon->getContent())[1])->toBe(192);
    $this->get(route('pwa.legacy-manifest'))->assertOk()->assertJsonPath('name', 'BIN HUSSEIN TRADERS');
    $this->get(route('pwa.static-legacy-manifest'))->assertOk()->assertJsonPath('name', 'BIN HUSSEIN TRADERS');
});

test('branding edits change manifest fingerprint and do not leave stale metadata', function () {
    $this->company->update(['company_name' => 'BIN HUSSEIN TRADERS']);
    $before = $this->get(route('pwa.manifest'))->json();
    $this->company->update(['company_name' => 'BIN HUSSEIN HARDWARE']);
    $this->settings->update(['theme_color' => '#445566']);

    $after = $this->get(route('pwa.manifest', ['v' => $before['version']]))->assertOk()->json();
    expect($after['name'])->toBe('BIN HUSSEIN HARDWARE')
        ->and($after['theme_color'])->toBe('#445566')
        ->and($after['version'])->not->toBe($before['version'])
        ->and($after['icons'][0]['src'])->not->toBe($before['icons'][0]['src']);
});

test('another company receives only its own PWA branding', function () {
    $this->company->update(['company_name' => 'BIN HUSSEIN TRADERS']);
    $this->settings->update(['theme_color' => '#112233']);
    $other = Company::create([
        'company_name' => 'MIKOPOSOFT', 'business_type' => 'Hardware Store',
        'phone' => '+255 700 777 777', 'whatsapp_number' => '+255 700 777 777',
    ]);
    $branch = Branch::create(['company_id' => $other->id, 'name' => 'Miko Branch', 'code' => 'MIKO']);
    Setting::withoutGlobalScopes()->create([
        'company_id' => $other->id, 'company_name' => 'MIKOPOSOFT',
        'theme_color' => '#654321', 'pwa_background_color' => '#eeeeee',
    ]);
    $otherUser = User::factory()->create(['company_id' => $other->id, 'branch_id' => $branch->id]);
    $this->actingAs($otherUser);

    $manifest = $this->get(route('pwa.manifest'))->assertOk()->json();
    expect($manifest['name'])->toBe('MIKOPOSOFT')
        ->and($manifest['theme_color'])->toBe('#654321')
        ->and($manifest['background_color'])->toBe('#eeeeee')
        ->and($manifest['name'])->not->toBe('BIN HUSSEIN TRADERS');
    $this->get(route('pwa.brand-icon', ['size' => 192]))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('unauthenticated manifest uses generic branding rather than the first company', function () {
    $this->company->update(['company_name' => 'SECRET COMPANY']);
    Auth::guard('web')->logout();
    Auth::guard('customer')->logout();

    $manifest = $this->get(route('pwa.manifest'))->assertOk()->json();
    expect($manifest['name'])->not->toBe('SECRET COMPANY')
        ->and($manifest['theme_color'])->toBe('#06b6d4')
        ->and($manifest['background_color'])->toBe('#ffffff');
    $this->get(route('pwa.brand-icon', ['size' => 192]))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('layout and install button render the current company branding', function () {
    $this->company->update(['company_name' => 'BIN HUSSEIN TRADERS']);
    $brand = app(PwaBrandingService::class)->brand();
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('manifest.webmanifest?v='.$brand['version'], false)
        ->assertSee('Install BIN HUSSEIN TRADERS App');
    expect(Blade::render('<x-pwa-install-button />'))->toContain('This app will use BIN HUSSEIN TRADERS branding.');
});

test('service worker refreshes safely without caching tenant branding', function () {
    $worker = file_get_contents(public_path('sw.js'));
    $install = file_get_contents(resource_path('js/pwa-install.js'));

    expect($worker)->toContain('hardex-pwa-v5', "key.startsWith('hardex-')", '/manifest.webmanifest',
        '/pwa/brand-icon/', "cache: 'no-store'")
        ->and($worker)->not->toContain("'/pwa/manifest.json',\n    '/images")
        ->and($install)->toContain('workerRegistration.update()', 'refreshBranding',
            'hardex-brand-updated', 'may require reinstalling the app');
});
