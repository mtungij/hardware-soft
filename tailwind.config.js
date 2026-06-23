import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './node_modules/preline/dist/*.js',
    ],

    theme: {
        extend: {
            colors: {
                navy: {
                    50: '#eef6ff',
                    100: '#d9ebff',
                    500: '#1e5f9f',
                    700: '#123d68',
                    800: '#0d2e50',
                    900: '#081f36',
                    950: '#06162a',
                },
                build: {
                    orange: '#06b6d4',
                    amber: '#f59e0b',
                },
            },
            fontFamily: {
                sans: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                soft: '0 18px 50px -28px rgba(15, 23, 42, .55)',
            },
        },
    },

    plugins: [
        forms,
    ],
};
