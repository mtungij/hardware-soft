@if ($document_type === 'quotation')
<label class="block text-sm font-bold">Quotation Template
    <select wire:model="quotation_template_key" class="mt-1 w-full rounded-xl border-slate-200 dark:bg-navy-950">
        <option value="">Use Company Default</option>
        @foreach (\App\Support\QuotationTemplateRegistry::all() as $template)
            <option value="{{ $template['key'] }}">{{ $template['name'] }}</option>
        @endforeach
    </select>
    @error('quotation_template_key')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
</label>
@endif
