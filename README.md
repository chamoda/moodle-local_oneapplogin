# One app login (local_oneapplogin)

Limits a Moodle account to one mobile app session at a time. The newest login wins; the device that
was logged in before is signed out.

## Why it is needed

Moodle stores one token per user per web service, not one per device. `\core_external\util::
generate_token_for_current_user()` reuses the existing `mdl_external_tokens` row — *"if some valid
tokens exist then use the most recent"* — so every device ends up holding the same token string, and
no setting changes that.

Revoking the token after a second device logs in would sign out both. So this plugin deletes the row
*before* Moodle looks it up, from the `\core\hook\after_config` hook that fires on every request. Moodle
then mints a fresh token for the device logging in, and the previous device is left holding a string
that no longer exists.

Covers both login flows: `/login/token.php` (username and password) and
`/admin/tool/mobile/launch.php` (browser/SSO login).

## Requirements

- Moodle 4.5. `version.php` declares `supported = [405, 405]`, and the code targets 4.5 only.
- Web services and the mobile service enabled (*Site administration → General → Mobile app → Mobile
  settings*).

## Installation

### From a ZIP in the admin panel

The archive must have exactly one top level folder named `oneapplogin` — the component name from
`version.php` with the plugin type prefix stripped, i.e. `local_oneapplogin` minus `local_`. Moodle
compares the folder against that stripped name and rejects anything else, so `local_oneapplogin` is
wrong too. The checkout is named `moodle-local_oneapplogin`, which is rejected outright (hyphens are
not valid in a plugin name), so rename it on the way in rather than zipping the directory as it sits:

```sh
git archive --format=zip --prefix=oneapplogin/ -o ../oneapplogin.zip HEAD
```

That packs the committed files only. To include uncommitted work, copy the checkout under the
required name first:

```sh
cd ..
cp -r moodle-local_oneapplogin oneapplogin
zip -r oneapplogin.zip oneapplogin -x 'oneapplogin/.git/*'
rm -rf oneapplogin
```

Then in Moodle: **Site administration → Plugins → Install plugins**, drop the ZIP into *ZIP package*,
leave *Plugin type* on "Detect type automatically" (`version.php` declares `local_oneapplogin`), and
click **Install plugin from the ZIP file**. Moodle shows a validation report; click **Continue**,
then **Upgrade Moodle database now**.

This needs `<moodleroot>/local` to be writable by the web server user. If it is not, Moodle greys the
option out with a warning, and you have to use the manual method below.

### Manually

Place the files so they land at `<moodleroot>/local/oneapplogin/version.php`, for example:

```sh
git clone https://your-host/moodle-local_oneapplogin.git local/oneapplogin
```

Then visit **Site administration → Notifications**, or run:

```sh
php admin/cli/upgrade.php
php admin/cli/purge_caches.php
```

### Afterwards

Confirm it under *Site administration → Plugins → Plugins overview*, then configure it at
*Site administration → Plugins → Local plugins → One app login*.

To uninstall, remove it from *Plugins overview* and delete `local/oneapplogin`. Existing tokens are
left alone; enforcement simply stops.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Enable single app session | Yes | Master switch. |
| Web services | `moodle_mobile_app` | Comma separated short names. `*` covers every service, including custom integrations using `/login/token.php`. |
| Restore token on failed login | Yes | Reinstates the previous token if the new login is refused after the password check. Leave on. |

## Limitations

- Browser sessions are untouched; this governs web service tokens only.
- Manually created tokens for the same user and service are deleted too. Give such integrations
  their own service and leave it out of the "Web services" setting.
- The revocation is logged even when the token is later restored, since the log entry is written
  before the outcome is known.
- OAuth2, CAS and Shibboleth passwords cannot be pre-verified, so the plugin skips them. Those
  accounts use the browser flow, which is covered.
- Depends on `\core\hook\after_config` firing on `login/token.php` — core behaviour, not a documented
  extension point for this. Re-verify after any upgrade beyond 4.5.

## Licence

Copyright 2026 Xaventra.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU
General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version. See [LICENSE](LICENSE) for the full text.
