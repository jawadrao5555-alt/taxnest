import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // plan-card partial builds classes dynamically (text-{{ $color }}-400/500),
    // so JIT can't see them — safelist the teal set used by Standalone plans.
    safelist: ['text-teal-400', 'text-teal-500', 'bg-teal-500', 'bg-teal-600'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
