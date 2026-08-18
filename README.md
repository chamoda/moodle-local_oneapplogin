# One app login (local_oneapplogin)

Prevents a Moodle account from being logged into the mobile app on more than one device at a
time. The newest login wins; the device that was logged in before is signed out.

## Why this is needed

Moodle stores **one token per user per web service**, not one per device. When the app calls
`/login/token.php`, `\core_external\util::generate_token_for_current_user()`
(`external_generate_token_for_login()` before Moodle 4.2) looks for existing rows in
`mdl_external_tokens` for that `userid` + `externalserviceid` and, in its own words, *"if some valid
tokens exist then use the most recent"* rather than minting a new one. Two devices therefore end up
holding the *same* token string, and there is no setting or API to change that behaviour.

Because the token is shared, revoking it *after* a second device logs in is useless — it would sign
out both devices. The only workable point of intervention is **before** Moodle looks the token up:
delete the row, let Moodle generate a fresh token for the device that is logging in, and the
previously logged in device is left holding a string that no longer exists.

## How it hooks in

Moodle's `after_config` callback is dispatched from `lib/setup.php` on every request, including
`/login/token.php` and `/admin/tool/mobile/launch.php`, and long before either script reaches its
token lookup. That makes it the one place a `local` plugin can get in front of the core logic.

- Moodle 3.5 – 4.3: the legacy `local_oneapplogin_after_config()` function in `lib.php`.
- Moodle 4.4+: `\core\hook\after_config` via `db/hooks.php`.

Both point at `\local_oneapplogin\manager::bootstrap()`, which is guarded so it runs at most once
per request no matter which path a given version takes.

Two login flows are covered:

| Flow | Script | How the user is identified |
| --- | --- | --- |
| Username/password login from the app | `/login/token.php` | `username` request parameter, resolved against `mdl_user` |
| Browser based / SSO app login | `/admin/tool/mobile/launch.php` | the existing browser session (`$USER`) |

Only permanent tokens (`tokentype = 0`) for the configured service are deleted.

## Authenticating before revoking

`/login/token.php` is a public, unauthenticated endpoint, and it has not checked the password at the
point `after_config` fires. `service=moodle_mobile_app` and a username in the request tell you what
*kind* of request this is, not that it is legitimate — the whole thing is one `curl` command. A
plugin that deleted tokens on sight would let anyone who knows a username repeatedly log that user's
app out without ever authenticating.

Calling `authenticate_user_login()` to check first is not an option either: on failure it calls
`login_attempt_failed()`, so verifying the password a second time per request would double the
lockout counter and lock accounts out at half the configured threshold.

What core actually uses to verify a password is a single line inside that function:

```php
$authplugin->user_login($username, $password)
```

That is the raw credential check — no lockout counter, no `user_login_failed` events, no error log
entries. `\local_oneapplogin\manager::credentials_are_valid()` calls exactly that, after mirroring
core's own suspended / `nologin` / disabled-auth / lockout checks, and tokens are only revoked when
it returns true. `resolve_user()` mirrors core's user lookup in the same way, including login by
email address when `$CFG->authloginviaemail` is enabled.

The cost is one extra password verification per app login — a second bcrypt comparison, or a second
LDAP bind on LDAP sites. Only on login requests, so the volume is negligible.

## The secondary safety net

Verifying the password is not quite the whole story: Moodle can still refuse to issue a token after
authentication succeeds — maintenance mode, an unconfirmed account, an expired password, a
restricted or unavailable service, or a user without `moodle/webservice:createmobiletoken`. In those
cases the old token is already gone and no new one arrives.

So the plugin snapshots the rows it deletes and registers a shutdown function. If the request ends
without a new token existing for that user and service, the original rows are reinserted with their
original ids and the existing device keeps working.

The trade-off is a very small window (the length of one `token.php` request) during which a web
service call from that device could come back with `invalidtoken`. The app recovers by prompting for
a fresh login.

## Moodle compatibility

Verified line by line against `MOODLE_405_STABLE` (Moodle 4.5), which is where it is intended to
run. `version.php` declares support back to Moodle 3.5, since nothing here uses newer APIs, but
older branches have not been checked against source.

On Moodle 4.5 both registration paths fire: `lib/setup.php` calls `process_legacy_callbacks()`
(which runs the `lib.php` function) *and* dispatches the hook (which runs the `db/hooks.php`
callback). `\core\hook\after_config` does not declare `after_config` as a deprecated callback, so
the dedup filter in `get_plugins_with_function()` does not strip the legacy one. The static guard in
`manager::bootstrap()` makes the second call a no-op — on 4.5 that guard is doing real work, not
just defending against a hypothetical.

Note also that the legacy path is skipped during installs and upgrades but the hook path is not,
which is why `process_request()` checks `during_initial_install()` and `$CFG->upgraderunning` itself.

## Installation

Copy this directory to `local/oneapplogin` in your Moodle root, then visit
**Site administration → Notifications** to complete the install.

```
moodle/local/oneapplogin/
```

## Settings

*Site administration → Plugins → Local plugins → One app login*

| Setting | Default | Notes |
| --- | --- | --- |
| Enable single app session | Yes | Master switch. |
| Web services | `moodle_mobile_app` | Comma separated short names. `*` enforces it for every service, including custom integrations that authenticate through `/login/token.php`. |
| Restore token on failed login | Yes | See "The secondary safety net" above. |

## Testing it

1. Log into the app on device A and confirm it works.
2. Log into the app on device B with the same account.
3. Pull to refresh on device A. It should report an expired session and ask for the password again.
4. Check *Site administration → Reports → Logs* for the "App token revoked" event.

To verify the credential gate, note the row in `mdl_external_tokens` for the user, then:

```sh
curl -d 'username=USER&password=wrong&service=moodle_mobile_app' https://example.org/login/token.php
```

The same token id and string must still be there afterwards, and device A must still work. Check
`mdl_user_preferences` too: `login_failed_count` for that user should have gone up by exactly one,
not two, confirming the pre-check did not touch the lockout counter.

## Limitations

- **Web browser sessions are untouched.** This only governs web service tokens. Use
  `\core\session\manager::kill_user_sessions()` from a `\core\event\user_loggedin` observer if you
  want the same rule for browser logins.
- **Any manually created token for the same user and service is also deleted** — for example one an
  administrator generated for that user under *Site administration → Server → Web services →
  Manage tokens*. If you rely on such tokens, give that integration its own service and leave it out
  of the "Web services" setting.
- **The revocation is logged even if the token is later restored**, because the log entry has to be
  written before the outcome of the request is known.
- **Auth methods that do not verify passwords through `user_login()`** — OAuth2, CAS, Shibboleth —
  cannot be pre-verified, so the gate returns false and the plugin does nothing. Those accounts
  cannot log in through `/login/token.php` anyway; they use the browser flow, which is covered.
- **Devices cannot be told apart**, so re-logging in on the *same* device also revokes and reissues.
  That is harmless, it just means the device gets a new token string.
- The plugin depends on `after_config` being dispatched on `login/token.php`. That has been true
  since Moodle 3.x, but it is core behaviour rather than a documented extension point for this
  purpose — re-run the test above after a major Moodle upgrade.
