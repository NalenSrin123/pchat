import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

// PCHAT has one product accent: teal.  Keep semantic feedback colours (red,
// amber, emerald) independent, but point legacy UI accent names at this same
// palette so every feature renders with the PCHAT brand instead of a mix of
// blue, indigo, violet, cyan, and teal.
const brand = colors.teal;

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            colors: {
                brand,
                teal: brand,
                blue: brand,
                indigo: brand,
                violet: brand,
                purple: brand,
                cyan: brand,
                sky: brand,
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
