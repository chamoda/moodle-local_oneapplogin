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

From the Moodle root, as the user that owns the code directory:

```sh
git clone https://your-host/oneapplogin.git local/oneapplogin
```

Or copy the files so they land at `<moodleroot>/local/oneapplogin/version.php`.

Then either visit **Site administration → Notifications** and follow the prompt, or run:

```sh
php admin/cli/upgrade.php
php admin/cli/purge_caches.php
```

Confirm it installed under *Site administration → Plugins → Plugins overview*, then configure it at
*Site administration → Plugins → Local plugins → One app login*.

To uninstall, remove it from *Plugins overview* and delete `local/oneapplogin`. Existing tokens are
left alone; enforcement simply stops.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Enable single app session | Yes | Master switch. |
| Web services | `moodle_mobile_app` | Comma separated short names. `*` covers every service, including custom integrations using `/login/token.php`. |
| Restore token on failed login | Yes | See below. Leave on. |

## How it decides to revoke

`/login/token.php` is public and has not checked the password when the hook fires. Deleting on
sight would let anyone who knows a username log that user's app out at will, so the plugin verifies
the credentials first.

It cannot use `authenticate_user_login()` for that — on failure that calls `login_attempt_failed()`,
which would double the lockout counter and lock accounts at half the configured threshold. Instead
it calls the same line core uses to check the password, `$authplugin->user_login()`, which has no
counters, events or log writes. Tokens are revoked only if that returns true.

The password being right is not quite enough, though: Moodle can still refuse a token afterwards
(maintenance mode, unconfirmed account, expired password, missing
`moodle/webservice:createmobiletoken`). So the deleted rows are snapshotted and reinserted at
shutdown if no new token appeared, rather than leaving the user signed out of the device they had.

## Testing

1. Log in on device A, then on device B with the same account.
2. Refresh device A — it should report an expired session and ask for the password.
3. Check *Reports → Logs* for the "App token revoked" event.

Credential gate:

```sh
curl -d 'username=USER&password=wrong&service=moodle_mobile_app' https://example.org/login/token.php
```

The token row must be unchanged, device A must still work, and `login_failed_count` in
`mdl_user_preferences` must rise by one, not two.

## Limitations

- Browser sessions are untouched; this governs web service tokens only.
- Manually created tokens for the same user and service are deleted too. Give such integrations
  their own service and leave it out of the "Web services" setting.
- The revocation is logged even when the token is later restored, since the log entry is written
  before the outcome is known.
- OAuth2, CAS and Shibboleth passwords cannot be pre-verified, so the plugin skips them. Those
  accounts use the browser flow, which is covered.
- Depends on `\core\hook\after_config` firing on `login/token.php` — core behaviour, not a documented
  extension point for this. Re-run the test above after any upgrade beyond 4.5.
