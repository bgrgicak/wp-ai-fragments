import { resolve } from 'node:path';
import { defineConfig } from 'vite';
import { viteSingleFile } from 'vite-plugin-singlefile';

export default defineConfig({
	root: 'assets',
	plugins: [viteSingleFile()],
	build: {
		outDir: '../build',
		emptyOutDir: true,
		rollupOptions: {
			input: resolve('assets/fragment-viewer.html'),
			output: {
				entryFileNames: 'fragment-viewer.js',
				assetFileNames: 'fragment-viewer.[ext]',
			},
		},
	},
});
