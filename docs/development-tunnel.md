# HTTPS tunnel for local development

Keep the existing WordPress site running. A public HTTPS tunnel lets the MCP viewer reach local admin pages when its browser cannot access loopback addresses.

Use a tunnel account and hostname you are authorized to use. [Jurassic Tube setup](https://github.com/Automattic/jetpack/blob/trunk/docs/quick-start.md#setting-up-jurassic-tube) is available to Automatticians; another HTTPS tunnel can use the same local proxy.

## Configure the public origin

Write your allocated HTTPS origin to the ignored local configuration file:

```sh
printf '%s\n' 'https://YOUR_SUBDOMAIN.jurassic.tube' > experiments/native-admin/.https-demo
node experiments/native-admin/public-proxy.mjs
```

Replace the placeholder with your assigned hostname. The file now contains an origin, rather than being an empty marker. WordPress, the STDIO bridge, the proxy, and live protocol tests read it. Alternatively, set `AIF_PROOF_PUBLIC_ORIGIN` in each process's private environment, including the WordPress PHP process; an exported variable in a terminal does not configure an already-running PHP process.

The value must be an HTTPS origin with no credentials, path, query, or fragment. It is applied only when WordPress's environment type is `local`. Remote installations use their existing site URLs.

## Forward the tunnel

Forward your assigned hostname to **127.0.0.1:8893**, the restricted proxy, rather than directly to WordPress on port 8888. With an installed and configured Jurassic Tube client:

```sh
jurassictube -u YOUR_JURASSIC_USERNAME -s YOUR_SUBDOMAIN -h 127.0.0.1:8893
```

If your provider supplies a manual SSH allocation, use its assigned remote port, SSH account, and private key path:

```sh
ssh -o BatchMode=yes -o ExitOnForwardFailure=yes -o ConnectTimeout=10 \
  -o ServerAliveInterval=30 -i /path/to/your/private-key \
  -T -N -R REMOTE_PORT:127.0.0.1:8893 SSH_ACCOUNT@TUNNEL_HOST
```

Allocation details vary by account and session. Keep them and private keys outside Git. Reuse an existing tunnel when possible. The proxy remains loopback-only and preserves its restrictions on login, XML-RPC, Authorization headers, and unauthenticated requests.

## Verify and stop

Rebuild the viewer, reconnect the MCP client after bridge changes, and open a fresh admin card. The bridge checks the configured public bootstrap endpoint before returning a tunnel card. Protocol success and visible browser acceptance are separate checks; see [TESTING.md](../TESTING.md).

Restart the proxy after proxy code changes. To finish, stop the tunnel and proxy, and remove the ignored `.https-demo` file or unset the private origin configuration. Do not restart Playground merely to refresh a card; starting it creates a fresh database.
