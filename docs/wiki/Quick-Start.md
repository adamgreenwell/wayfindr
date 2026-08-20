# Quick Start

[Back to Home](Home)

The fastest evaluation path is a fresh Linux VM with Docker and the Compose
plugin. Use a disposable machine until you have worked through backup, restore,
mail, storage, and upgrade drills.

## 1. Prepare the Host

Plan for 1 GB of RAM to try Wayfindr, 2 GB for real traffic, and roughly 1.5 GB
more if you enable ClamAV.

A **publicly-issued** certificate needs a DNS record pointing at the VM and
ports 80 and 443 free. Evaluating on a private network needs neither — see
below.

## 2. Install

For a real support hostname, with a certificate from a public authority:

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com
```

For evaluation on a private network, name the address you will actually browse
to. The certificate is issued locally during install, so no DNS record and no
public port are required:

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://192.168.1.50
```

Any of these work as `--app-url`, and the port you name is the port that
serves:

| Value | Use it when |
| --- | --- |
| `https://support.example.com` | A real hostname, reachable from the internet |
| `https://192.168.1.50` | Browsing from another machine on your network |
| `https://wayfindr.local` | Same, with a name — you still arrange DNS or mDNS yourself |
| `localhost` | Browsing **on the VM itself**; binds to loopback only |
| `https://localhost:2345` | The same, on a port of your choosing |

A bare host without a scheme is accepted: loopback becomes `http://`,
everything else `https://`, and the installer prints the URL it settled on.

The installer writes the stack to `./wayfindr`, generates secrets, starts the
services, runs migrations, waits for health, and prints the first-run URL.

**`localhost` binds to loopback only.** That is deliberate — nothing is exposed
to your network — but it means you cannot reach it from another machine. If you
plan to browse from your laptop, use the VM's address or a name, not
`localhost`.

## 3. Trust the Local Certificate, If You Have One

This step applies only to a **direct** `https://` install at an address no
public authority can issue for — an IP address, `https://localhost`, or a
`.local`-style name. Wayfindr signs the certificate itself, and browsers warn
until that root is trusted. The warning is expected, not a sign of a broken
install.

It does **not** apply in two cases:

- **A bare `localhost`**, which infers `http://` and has no certificate at all.
  Pass `https://localhost` if you want TLS locally.
- **`--behind-proxy`**, where your own proxy terminates TLS and issues the
  certificate. Wayfindr signs nothing and prints no export command in that
  mode, so a certificate problem there is your proxy's to solve.

The installer prints the URL it settled on, and prints the command to export
the root when this step applies — so its closing output tells you which case
you are in.

Add the exported `wayfindr-local-ca.crt` to the trust store of each machine you
browse from (on macOS via Keychain Access, on Debian/Ubuntu by copying it to
`/usr/local/share/ca-certificates/` and running `update-ca-certificates`), then
restart the browser.

The root lives in a Docker volume, so it survives upgrades and only has to be
trusted once per machine.

## 4. Finish First-Run Setup

Visit `/setup`, create the first account owner and site, then review `/operator`
as the platform operator. Before using real visitor data:

1. Configure and smoke-test outbound mail.
2. Confirm the queue, backup queue, scheduler, and Reverb are healthy.
3. Send a visitor message and reply from the agent dashboard.
4. Take a backup and restore it on a disposable VM.
5. Record the running release shown by the operator console.

The complete and authoritative procedure is the repository's
[self-hosting install guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/install.md).
