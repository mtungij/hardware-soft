<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;

use function Livewire\Volt\rules;
use function Livewire\Volt\state;

state(['password' => '']);

rules(['password' => ['required', 'string', 'current_password']]);

$deleteUser = function (Logout $logout) {
    $this->validate();

    tap(Auth::user(), $logout(...))->delete();

    $this->redirect('/', navigate: true);
};

?>

<section class="space-y-6">
    <header>
        <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ __(__('terminology.delete_account_ee1b9a9f')) }}
        </h2>

        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            {{ __(__('terminology.once_your_account_is_deleted_all_of_its_resources_and_data_will_be_permanen_f03b61f0')) }}
        </p>
    </header>

    <x-danger-button
        x-data=""
        x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
    >{{ __(__('terminology.delete_account_ee1b9a9f')) }}</x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="p-6">

            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ __(__('terminology.are_you_sure_you_want_to_delete_your_account_65850961')) }}
            </h2>

            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __(__('terminology.once_your_account_is_deleted_all_of_its_resources_and_data_will_be_permanen_385f672a')) }}
            </p>

            <div class="mt-6">
                <x-input-label for="password" value="{{ __('application.password_8be3c943') }}" class="sr-only" />

                <x-text-input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    class="mt-1 block w-3/4"
                    placeholder="{{ __('application.password_8be3c943') }}"
                />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="mt-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">
                    {{ __('application.cancel_77dfd213') }}
                </x-secondary-button>

                <x-danger-button class="ms-3">
                    {{ __(__('terminology.delete_account_ee1b9a9f')) }}
                </x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
