import {fileURLToPath} from 'node:url';
import {build} from 'vite';
import {viteSingleFile} from 'vite-plugin-singlefile';

const root = fileURLToPath(new URL('.', import.meta.url));
// The PHP MCP resource serves this standalone HTML bundle.
await build({
  configFile:false,
  root,
  plugins:[viteSingleFile()],
  build:{outDir:'dist', emptyOutDir:true, rollupOptions:{input:root + 'view.html'}},
});
