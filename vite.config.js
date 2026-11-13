import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
	plugins: [svelte()],
	css: {
		postcss: './postcss.config.js',
	},
	build: {
		outDir: 'assets/dist',
		rollupOptions: {
			input: {
				'dragongate-portal': 'src/index.js',
				'dragongate-public': 'src/public/index.js',
			},
			output: {
				entryFileNames: '[name].js',
				chunkFileNames: '[name].js',
				assetFileNames: (assetInfo) => {
					if (assetInfo.name === 'styles.css') {
						return 'dragongate-portal.css';
					}
					return '[name].[ext]';
				},
			},
		},
	},
	optimizeDeps: {
		include: ['svelte'],
	},
});
