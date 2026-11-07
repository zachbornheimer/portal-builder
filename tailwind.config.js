/** @type {import('tailwindcss').Config} */
export default {
	content: [
		'./src/**/*.{js,svelte}',
		'./includes/**/*.php',
		'./assets/**/*.css',
	],
	theme: {
		extend: {
			colors: {
				// DragonGate tokens (canonical values in src/tokens.css)
				ink: {
					DEFAULT: 'var(--ink)',
					800: 'var(--ink-800)',
					600: 'var(--ink-600)',
					400: 'var(--ink-400)',
				},
				paper: {
					DEFAULT: 'var(--paper)',
					50: 'var(--paper-50)',
					100: 'var(--paper-100)',
				},
				ember: {
					DEFAULT: 'var(--ember)',
					100: 'var(--ember-100)',
					600: 'var(--ember-600)',
				},
				brass: {
					DEFAULT: 'var(--brass)',
					200: 'var(--brass-200)',
				},
				success: {
					DEFAULT: 'var(--success)',
					100: 'var(--success-100)',
				},
				error: {
					DEFAULT: 'var(--error)',
					100: 'var(--error-100)',
				},
				// Legacy — do not use in new wizard UI
				'zysys-blue': {
					50: '#f3f7fc',
					100: '#e6eff8',
					200: '#c7dff0',
					300: '#a8cee8',
					400: '#5ea5d2',
					500: '#398abe',
					600: '#286ea1',
					700: '#225882',
					800: '#204c6c',
					900: '#1f405b',
					950: '#15293c',
				},
			},
		},
	},
	plugins: [],
	corePlugins: {
		// Disable unused core plugins to reduce bundle size
		preflight: false, // Disable Tailwind's base styles
	},
};
