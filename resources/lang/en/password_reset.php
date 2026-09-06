<?php

return [
    'request' => [
        'title' => 'Forgot Password?',
        'description' => 'Enter the email address associated with your account. We will send you a password reset link.',
        'email' => 'Email Address',
        'submit' => 'Send Password Reset Link',
        'sending' => 'Sending...',
        'back' => 'Back to Login',
        'success' => 'We have sent a password reset link to your email address.',
        'failure' => 'We could not send the email right now. Please try again.',
    ],
    'reset' => [
        'title' => 'Reset Password',
        'description' => 'Choose a secure new password for your HARDEX account.',
        'email' => 'Email Address',
        'password' => 'New Password',
        'password_confirmation' => 'Confirm Password',
        'submit' => 'Reset Password',
        'resetting' => 'Resetting...',
        'success' => 'Your password has been reset successfully. You can now sign in.',
    ],
    'email' => [
        'brand' => 'HARDEX Hardware ERP',
        'subject' => 'Reset Your HARDEX Password',
        'greeting' => 'Hello,',
        'intro' => 'We received a request to reset the password for your HARDEX account.',
        'action' => 'Reset Password',
        'expiry' => 'For security, this link will expire in :minutes minutes, according to the configured password reset expiry time.',
        'ignore' => 'If you did not request a password reset, you can ignore this email.',
        'footer' => 'HARDEX Hardware ERP',
    ],
];
