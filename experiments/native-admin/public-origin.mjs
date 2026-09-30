// Private local tunnel configuration shared by the proxy and STDIO bridge.
import {readFileSync, existsSync} from 'node:fs';

export function publicOrigin(env = process.env, marker = new URL('./.https-demo', import.meta.url)) {
  const configured = env.AIF_PROOF_PUBLIC_ORIGIN || (existsSync(marker) ? readFileSync(marker, 'utf8').trim() : '');
  if (!configured) return '';
  const url = new URL(configured);
  if (url.protocol !== 'https:' || url.username || url.password || url.pathname !== '/' || url.search || url.hash) {
    throw Error('AIF_PROOF_PUBLIC_ORIGIN must be an HTTPS origin without credentials, a path, query, or fragment.');
  }
  return url.origin;
}
