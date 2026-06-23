<button {{ $attributes->merge(['type' => 'submit', 'class' => 'erp-btn-primary']) }}>
    {{ $slot }}
</button>
