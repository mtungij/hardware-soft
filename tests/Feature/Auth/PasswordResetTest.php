<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserPreference;
use App\Notifications\HardexResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;
use RuntimeException;

test('staff login links to the localized forgot password page', function (string $locale, string $label) {
    $this->withSession(['staff_locale' => $locale])
        ->get(route('login'))
        ->assertOk()
        ->assertSee($label)
        ->assertSee(route('password.request'), escape: false);
})->with([
    ['en', 'Forgot Password?'],
    ['sw', 'Umesahau Nenosiri?'],
]);

test('forgot password page is localized', function (string $locale, string $title, string $description, string $button) {
    $this->withSession(['staff_locale' => $locale])
        ->get(route('password.request'))
        ->assertOk()
        ->assertSeeVolt('pages.auth.forgot-password')
        ->assertSeeText($title)
        ->assertSeeText($description)
        ->assertSeeText($button);
})->with([
    ['en', 'Forgot Password?', 'Enter the email address associated with your account. We will send you a password reset link.', 'Send Password Reset Link'],
    ['sw', 'Umesahau Nenosiri?', 'Weka barua pepe uliyotumia kufungua akaunti. Tutakutumia kiungo cha kubadili nenosiri.', 'Tuma Kiungo cha Kubadili Nenosiri'],
]);

test('password reset request creates a broker token and sends a HARDEX notification', function () {
    Notification::fake();
    $user = User::factory()->create();

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink')
        ->assertHasNoErrors();

    Notification::assertSentTo($user, HardexResetPassword::class, function (HardexResetPassword $notification) use ($user) {
        expect(Password::broker()->tokenExists($user, $notification->token))->toBeTrue();

        $mail = $notification->toMail($user);

        expect($mail->actionUrl)
            ->toContain('/reset-password/'.$notification->token)
            ->toContain('email='.urlencode($user->email));

        return true;
    });
});

test('reset email uses the persisted staff language and configured expiry', function () {
    Notification::fake();
    config(['auth.passwords.users.expire' => 37]);
    app()->setLocale('en');

    $user = User::factory()->create();
    UserPreference::query()->create([
        'guard' => 'web',
        'user_id' => $user->id,
        'key' => 'locale',
        'value' => 'sw',
    ]);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, HardexResetPassword::class, function (HardexResetPassword $notification) use ($user) {
        $mail = $notification->toMail($user);

        expect($mail->subject)->toBe('Badilisha Nenosiri lako la HARDEX')
            ->and($mail->greeting)->toBe('HARDEX Hardware ERP')
            ->and($mail->actionText)->toBe('Badilisha Nenosiri')
            ->and(implode(' ', [...$mail->introLines, ...$mail->outroLines]))->toContain('dakika 37');

        return true;
    });
});

test('reset password page is localized', function (string $locale, string $title, string $passwordLabel, string $button) {
    $this->withSession(['staff_locale' => $locale])
        ->get(route('password.reset', ['token' => 'test-token', 'email' => 'staff@example.com']))
        ->assertOk()
        ->assertSeeVolt('pages.auth.reset-password')
        ->assertSeeText($title)
        ->assertSeeText($passwordLabel)
        ->assertSeeText($button);
})->with([
    ['en', 'Reset Password', 'New Password', 'Reset Password'],
    ['sw', 'Badilisha Nenosiri', 'Nenosiri Jipya', 'Badilisha Nenosiri'],
]);

test('password can be reset with a valid token and the old password stops working', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, HardexResetPassword::class, function (HardexResetPassword $notification) use ($user) {
        Volt::test('pages.auth.reset-password', ['token' => $notification->token])
            ->set('email', $user->email)
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('resetPassword')
            ->assertHasNoErrors()
            ->assertRedirect(route('login'));

        $user->refresh();

        expect(Hash::check('NewPassword123!', $user->password))->toBeTrue()
            ->and(Hash::check('OldPassword123!', $user->password))->toBeFalse()
            ->and(Password::broker()->tokenExists($user, $notification->token))->toBeFalse();

        return true;
    });
});

test('invalid reset token is rejected with a localized message', function () {
    app()->setLocale('sw');
    $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);

    Volt::test('pages.auth.reset-password', ['token' => 'invalid-token'])
        ->set('email', $user->email)
        ->set('password', 'NewPassword123!')
        ->set('password_confirmation', 'NewPassword123!')
        ->call('resetPassword')
        ->assertHasErrors(['email'])
        ->assertSee('Kiungo hiki cha kubadili nenosiri si sahihi au muda wake umeisha.');

    expect(Hash::check('OldPassword123!', $user->fresh()->password))->toBeTrue();
});

test('expired reset token is rejected', function () {
    Notification::fake();
    config(['auth.passwords.users.expire' => 1]);
    $user = User::factory()->create();

    Password::sendResetLink(['email' => $user->email]);

    Notification::assertSentTo($user, HardexResetPassword::class, function (HardexResetPassword $notification) use ($user) {
        $this->travel(2)->minutes();

        Volt::test('pages.auth.reset-password', ['token' => $notification->token])
            ->set('email', $user->email)
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('resetPassword')
            ->assertHasErrors(['email']);

        return true;
    });
});

test('mail provider failure is logged safely and shown as a localized generic error', function () {
    app()->setLocale('sw');
    $user = User::factory()->create();

    Log::spy();
    Password::shouldReceive('sendResetLink')
        ->once()
        ->andThrow(new RuntimeException('RESEND_KEY=re_secret_provider_value token=secret-token'));

    Volt::test('pages.auth.forgot-password')
        ->set('email', $user->email)
        ->call('sendPasswordResetLink')
        ->assertHasErrors(['email'])
        ->assertSee('Imeshindikana kutuma barua pepe kwa sasa. Tafadhali jaribu tena.')
        ->assertDontSee('re_secret_provider_value')
        ->assertDontSee('secret-token');

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Password reset email delivery failed.'
            && $context['exception'] === RuntimeException::class
            && isset($context['email_hash'])
            && ! str_contains(json_encode($context), 're_secret_provider_value')
            && ! str_contains(json_encode($context), 'secret-token');
    });
});

test('resend api key is never rendered in authentication pages', function (string $route) {
    config(['services.resend.key' => 're_frontend_secret_value']);

    $this->get($route)
        ->assertOk()
        ->assertDontSee('re_frontend_secret_value');
})->with([
    fn () => route('login'),
    fn () => route('password.request'),
    fn () => route('password.reset', ['token' => 'test-token', 'email' => 'staff@example.com']),
]);
