# Quick Start

[Back to Home](Home)

The fastest evaluation path is a fresh Linux VM with Docker and the Compose
plugin. Use a disposable machine until you have worked through backup, restore,
mail, storage, and upgrade drills.

## 1. Prepare the Host

Plan for 1 GB of RAM to try Wayfindr, 2 GB for real traffic, and roughly 1.5 GB
more if you enable ClamAV. Automatic HTTPS also needs a DNS record pointing at
the VM and ports 80 and 443 available.

## 2. Install

```bash
curl -fsSL https://raw.githubusercontent.com/adamgreenwell/wayfindr/main/scripts/self-host/install.sh \
  | bash -s -- --app-url https://support.example.com
```

The installer writes the stack to `./wayfindr`, generates secrets, starts the
services, runs migrations, waits for health, and prints the first-run URL.

## 3. Finish First-Run Setup

Visit `/setup`, create the first account owner and site, then review `/operator`
and `/dashboard/readiness`. Before using real visitor data:

1. Configure and smoke-test outbound mail.
2. Confirm the queue, backup queue, scheduler, and Reverb are healthy.
3. Send a visitor message and reply from the agent dashboard.
4. Take a backup and restore it on a disposable VM.
5. Record the running release shown by the operator console.

The complete and authoritative procedure is the repository's
[self-hosting install guide](https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/install.md).
