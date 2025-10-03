/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./src/**/*.{js,svelte}",
        "./includes/**/*.php",
        "./assets/**/*.css"
    ],
    theme: {
        extend: {
            colors: {
                'zysys-blue': {
                    '50': '#f3f7fc',
                    '100': '#e6eff8',
                    '200': '#c7dff0',
                    '300': '#a8cee8',
                    '400': '#5ea5d2',
                    '500': '#398abe',
                    '600': '#286ea1',
                    '700': '#225882',
                    '800': '#204c6c',
                    '900': '#1f405b',
                    '950': '#15293c',
                }
            }
        },
    },
    plugins: [],
    corePlugins: {
        // Disable unused core plugins to reduce bundle size
        preflight: false, // Disable Tailwind's base styles
    }
}
