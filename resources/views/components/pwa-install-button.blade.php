@props([
    'class' => 'hidden rounded-xl bg-build-orange px-4 py-2.5 text-sm font-black text-white shadow-lg shadow-orange-500/25',
])

<button
    type="button"
    data-pwa-install-button
    class="{{ $class }}"
>
    Install Hardex App
</button>
