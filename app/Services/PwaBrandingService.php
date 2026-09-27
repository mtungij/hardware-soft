<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PwaBrandingService
{
    public function company(): ?Company
    {
        $companyId = $this->customerContext()
            ? Auth::guard('customer')->user()?->company_id
            : (Auth::guard('web')->user()?->company_id ?: Auth::guard('customer')->user()?->company_id);

        return $companyId ? Company::query()->find($companyId) : null;
    }

    public function settings(): ?Setting
    {
        $company = $this->company();
        if (! $company) {
            return null;
        }

        try {
            return Setting::withoutGlobalScopes()->where('company_id', $company->id)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{name: string, short_name: string, theme_color: string, background_color: string, version: string, tenant_key: string, has_logo: bool} */
    public function brand(): array
    {
        $company = $this->company();
        $settings = $company ? $this->settings() : null;
        $name = trim((string) ($company?->company_name ?: $settings?->company_name ?: config('app.name', 'Hardex POS')));
        $name = $name ?: 'Hardex POS';
        $shortName = Str::of($name)->squish()->limit(24, '')->value() ?: 'Hardex';
        $theme = $this->validColor($settings?->theme_color, '#06b6d4');
        $background = $this->validColor($settings?->pwa_background_color, '#ffffff');
        $logo = $company?->logo ?: $settings?->company_logo;
        $hasLogo = $company && $logo && $this->logoBytes($logo) !== null;
        $version = substr(hash('sha256', implode('|', [
            $company?->id ?: 'generic', $name, $shortName, $theme, $background,
            $logo ?: '', $company?->updated_at?->toISOString() ?: '',
            $settings?->updated_at?->toISOString() ?: '',
        ])), 0, 16);

        return [
            'name' => $name,
            'short_name' => $shortName,
            'theme_color' => $theme,
            'background_color' => $background,
            'version' => $version,
            'tenant_key' => $company ? substr(hash('sha256', (string) $company->id), 0, 12) : 'generic',
            'has_logo' => (bool) $hasLogo,
        ];
    }

    public function iconUrl(int $size, ?array $brand = null): string
    {
        $brand ??= $this->brand();

        return route('pwa.brand-icon', ['size' => $size, 'v' => $brand['version'],
            ...($this->customerContext() ? ['portal' => 'customer'] : [])]);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        $brand = $this->brand();
        $isCustomerPortal = $this->customerContext();

        return [
            'name' => $brand['name'],
            'short_name' => $brand['short_name'],
            'description' => $isCustomerPortal
                ? 'Customer portal for accounts, receipts, payments, and statements.'
                : 'Staff workspace for inventory, sales, accounting, and reporting.',
            'theme_color' => $brand['theme_color'],
            'background_color' => $brand['background_color'],
            'display' => 'standalone',
            'orientation' => 'portrait',
            'start_url' => $isCustomerPortal ? '/customer/login' : '/login',
            'scope' => '/',
            'id' => $isCustomerPortal ? '/customer' : '/staff',
            'categories' => ['business', 'productivity', 'finance'],
            'version' => $brand['version'],
            'icons' => collect([192, 512])->map(fn (int $size) => [
                'src' => $this->iconUrl($size, $brand),
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any maskable',
            ])->all(),
        ];
    }

    public function icon(int $size): string
    {
        $size = in_array($size, [192, 512], true) ? $size : 192;
        $company = $this->company();
        $settings = $company ? $this->settings() : null;
        $logo = $company?->logo ?: $settings?->company_logo;
        $bytes = $company && $logo ? $this->logoBytes($logo) : null;

        if ($bytes && function_exists('imagecreatefromstring')) {
            $dimensions = @getimagesizefromstring($bytes);
            if ($dimensions && $dimensions[0] <= 4096 && $dimensions[1] <= 4096) {
                $source = @imagecreatefromstring($bytes);
                if ($source !== false) {
                    $canvas = imagecreatetruecolor($size, $size);
                    $background = $this->brand()['background_color'];
                    $color = imagecolorallocate($canvas, hexdec(substr($background, 1, 2)),
                        hexdec(substr($background, 3, 2)), hexdec(substr($background, 5, 2)));
                    imagefilledrectangle($canvas, 0, 0, $size, $size, $color);
                    $width = imagesx($source);
                    $height = imagesy($source);
                    $scale = min(($size * .7) / $width, ($size * .7) / $height);
                    $drawWidth = max(1, (int) round($width * $scale));
                    $drawHeight = max(1, (int) round($height * $scale));
                    imagecopyresampled($canvas, $source, (int) (($size - $drawWidth) / 2),
                        (int) (($size - $drawHeight) / 2), 0, 0, $drawWidth, $drawHeight, $width, $height);
                    ob_start();
                    imagepng($canvas);
                    $image = ob_get_clean();
                    imagedestroy($canvas);
                    imagedestroy($source);

                    if (is_string($image)) {
                        return $image;
                    }
                }
            }
        }

        return file_get_contents(public_path("icons/icon-{$size}x{$size}.png")) ?: '';
    }

    public function customerContext(): bool
    {
        return request()->query('portal') === 'customer'
            || request()->routeIs('customer.*')
            || request()->getHost() === parse_url(config('app.customer_portal_url'), PHP_URL_HOST);
    }

    private function validColor(?string $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
    }

    private function logoBytes(string $path): ?string
    {
        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path) || $disk->size($path) > 2 * 1024 * 1024) {
                return null;
            }
            $bytes = $disk->get($path);
            $mime = @getimagesizefromstring($bytes)['mime'] ?? null;

            return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? $bytes : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
