# MailProbe for WordPress and WooCommerce

Refuses sign-ups with an email address that cannot receive emails or is disposable, and flags WooCommerce orders whose billing email has the same problem. The checks are made by [MailProbe](https://mailprobe.dev), an email verification API hosted in the EU (France).

The user documentation is [`readme.txt`](readme.txt), in the format of the WordPress.org plugin directory.

## What it does

| Where | When | What happens |
|---|---|---|
| WordPress registration form (`wp-login.php`) | before the account is created | refused when the address cannot receive emails, is disposable, or (optional) is a shared address such as `info@` |
| WooCommerce "My account" registration | same | same |
| WooCommerce checkout, classic and blocks | after the order, in the background (Action Scheduler) | never blocked; a flagged order gets a private note and a red mark in the orders list |

Rules that do not change with settings:

- Addresses that cannot be confirmed (catch-all domains, providers that block probes such as Outlook, slow servers) are never refused.
- Any error of MailProbe (network, no credits, key refused, timeout) lets the visitor through. The errors that stop every check (no credits, key refused) raise an admin notice until a check succeeds again.
- At registration the visitor waits 3 seconds at most (`mailprobe_registration_wait`). The API is asked for 5 seconds, its minimum; a call cut while the address is still being probed is not charged.
- Every call carries the User-Agent `mailprobe-wordpress/<version> WordPress/<version> WooCommerce/<version>`, without the site address. MailProbe records `mailprobe-wordpress` on the API key that the plugin uses first: that is how the accounts brought by the plugin are counted.

## Hooks

- `mailprobe_registration_message` (filter): the message shown when a registration is refused. Arguments: message, reason (`invalid`, `disposable`, `role`), MailProbe result.
- `mailprobe_registration_wait` (filter): seconds a visitor waits at most, default 3.
- `mailprobe_order_checked` (action): an order was checked. Arguments: `WC_Order`, verdict (`valid`, `risky`, `unknown`, `role`, `invalid`, `disposable`), MailProbe result.

## Configuration constants

- `MAILPROBE_API_KEY`: the API key, instead of the settings page.
- `MAILPROBE_API_BASE`: the API root, `https://mailprobe.dev/api/v1` by default. Only for tests.

## Release

```bash
bash bin/build-zip.sh
```

builds `dist/mailprobe-<version>.zip` from the last commit, without the development files (`.gitattributes`, `export-ignore`). That zip is what goes to the WordPress.org plugin directory.

## License

GPLv2 or later. Copyright 2026 Iris Digital.
