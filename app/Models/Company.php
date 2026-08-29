<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'company_name',
    'business_type',
    'tagline',
    'tin_number',
    'vrn_number',
    'show_tax_identifiers_on_receipt',
    'phone',
    'alternate_phone',
    'whatsapp_number',
    'email',
    'website',
    'address',
    'region',
    'district',
    'country',
    'logo',
    'description',
    'currency',
    'timezone',
    'language',
    'manufacturing_enabled',
])]
class Company extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'show_tax_identifiers_on_receipt' => 'boolean',
            'manufacturing_enabled' => 'boolean',
        ];
    }

    public static function current(): ?self
    {
        try {
            if (! Schema::hasTable('companies')) {
                return null;
            }

            $user = Auth::guard('web')->user();

            if ($user instanceof User && ! $user->is_system_owner && $user->company_id) {
                return self::query()->find($user->company_id);
            }

            return self::query()->first();
        } catch (\Throwable) {
            return null;
        }
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(CompanyPaymentMethod::class);
    }

    public function additionalChargeTypes(): HasMany
    {
        return $this->hasMany(AdditionalChargeType::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    public function productFamilies(): HasMany
    {
        return $this->hasMany(ProductFamily::class);
    }

    public function productionMoulds(): HasMany
    {
        return $this->hasMany(ProductionMould::class);
    }

    public function productionMouldInstallations(): HasMany
    {
        return $this->hasMany(ProductionMouldInstallation::class);
    }

    public function productionMachineAssignments(): HasMany
    {
        return $this->hasMany(ProductionMachineAssignment::class);
    }

    public function productionRecipes(): HasMany
    {
        return $this->hasMany(ProductionRecipe::class);
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function productionCuringBatches(): HasMany
    {
        return $this->hasMany(ProductionCuringBatch::class);
    }

    public function productionOrderCostings(): HasMany
    {
        return $this->hasMany(ProductionOrderCosting::class);
    }

    public function whatsappLink(): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->whatsapp_number);

        return $number ? 'https://wa.me/'.$number : null;
    }

    public function manufacturingEnabled(): bool
    {
        return (bool) ($this->manufacturing_enabled ?? false);
    }
}
