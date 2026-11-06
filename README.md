# DragonGate Portals

DragonGate Portals is a WordPress plugin for building public application forms. Submissions land in Google Sheets and Google Drive — not a WordPress applications inbox.

Created by Z. Bornheimer (ZYSYS).
Learn more at [allintersections.com](https://allintersections.com) or [zysys.org](https://zysys.org)

## Requirements

- **Requires PHP:** 8.1 or higher
- **Requires WordPress:** 6.4 or higher (Requires at least: 6.4)

## Development

`mise` is the task entrypoint. Node installs go through [aube](https://aube.jdx.dev). PHP deps go through Composer.

```sh
mise run setup
mise run test
mise run ci
mise run cut-release          # tag current (or next) semver + zip
CUT_RELEASE_PUSH=1 mise run cut-release
```

## First hour: ZIP install to a first test submit

Every control named below is a label that exists in this plugin.

1. **Download the plugin ZIP** from the [Releases](https://github.com/zachbornheimer/portal-builder/releases) page.

2. **Upload and install.** In WordPress admin, go to **Plugins → Add New → Upload Plugin**. Choose the ZIP and click **Install Now**.

3. **Activate.** Click **Activate** to enable DragonGate Portals.

4. **Paste Google API keys.** Open **Portals → Default Settings**. Under **Google API Keys**:
   - Paste the **OAuth client JSON** (Desktop or Web client from Google Cloud) into **Google Secret Key**. This is not a service-account key and not Storage Admin.
   - Paste the **access token** for the Google identity that completed OAuth consent into **Google Access Key**. FileStore refreshes this token.
   - Share the destination Drive folder and Sheet with that same Google identity (the person who completed consent), not a service-account email.

   Click **Test write** with a scratch Sheet ID and Drive folder. A `DRAGONGATE_TEST` row means credentials work. Delete that row. After a successful test write, the connect-Google checklist dismisses.

   After save, Settings shows **Key set** plus the last four characters of each Google secret. Leave the box blank to keep the saved value. Paste a new value to rotate.

   The full steps live on **Portals → Google API Setup** (**Google API Setup Instructions**).

5. **Add a portal.** Open **Portals → Add New Portal**. The definition wizard opens.

6. **Start.** On **Start**, pick the recommended **Basic application** template (applicant pack + one text field + one file). Festival examples such as **Call for Scores** stay available as named templates.

7. **Build form.** On **Build form**, add or edit the fields applicants will fill.

8. **Map dests.** On **Map data**, paste each Google Sheet URL and Drive folder. Name each dest. Map every form field to a dest. Sheet column headers = dest names.

9. **Status.** On **Status**, set the launch date and deadline, then click **Save portal** to publish. The submission switch reflects actual availability: it stays off and disabled while unpublished or outside the submission window. Inside the window, use it to pause or resume submissions. Send an invitation from **Records** to accept a late submission. The **Public form** URL appears on this step — use **Copy link** or **View portal**.

10. **Send a test submit** through the public form. The row and files appear in the mapped Google Sheet and Google Drive folder.

11. **Staff console.** Open the portal in the wizard. The **Records** step shows the merged submission records — applicant name, WordPress user, submitted time, application id, status, and per-destination results — and an **Invitations** section for inviting someone to submit after the portal closes. An administrator (or a user with the `dg_edit_submissions` capability) can replace a file; that write goes through the Drive dest adapter. Google Sheet and Drive remain the packet of record for **Submission packets** — this is an operator console, not a second store.

12. **Applicant packets.** A signed-in applicant sees **Your submissions for this portal** on the public form. They can **Replace file** on a current packet (same Drive dest adapter) or **Recall** it so staff no longer treat it as current.

Cloudflare Turnstile (set **both** the site key and secret under Default Settings) gates public submits for every audience — anyone, logged-in, and members. Incomplete keys leave the gate off so applicants are not blocked; finish both keys before relying on spam protection.

## Upgrade by ZIP

GitHub Releases are the update channel. This plugin is not on WordPress.org.

**WordPress Dashboard.** Open **Dashboard → Updates** (or **Plugins**). When a newer **stable** GitHub Release exists (`vMAJOR.MINOR.PATCH` with a `portal-builder-*.zip` asset), DragonGate Portals appears as an update. Click **Update now**. The install folder is `dragongate-portals` (the release ZIP unpacks under that name). Legacy folders (`portal-builder`, `portal-builder-0.0.4a`) self-relocate on load. Portal posts and `_portal_definition` stay in the database.

Rolling / prerelease tags are ignored. Manual ZIP replace still works:

1. **Download the new ZIP** from the [Releases](https://github.com/zachbornheimer/portal-builder/releases) page.

2. **Replace the plugin ZIP.** In WordPress admin, go to **Plugins → Add New → Upload Plugin**. Choose the new ZIP. When WordPress offers to replace the current plugin, accept that. Do not delete the plugin folder or portal posts first.

3. **Activate** if WordPress deactivated the plugin during the replace.

## Features

- Definition wizard: **Start** → **Build form** → **Map data** → **Status** → **Records**
- Google Sheets for answers, Google Drive for files
- One **Public form** URL per portal
- Site-wide defaults under **Portals → Default Settings**

## Building the plugin

Contributor path only. Strangers installing from a ZIP should follow [First hour](#first-hour-zip-install-to-a-first-test-submit).

### Requirements

- **PHP 8.1 or higher**
- **Composer**
- **Node.js** (optional, for asset building)

### Build Process

1. **Clone the Repository:**

   ```sh
   git clone --recurse-submodules https://github.com/zachbornheimer/portal-builder.git
   cd portal-builder
   ```

2. **Install Dependencies:**

   - For production:

     ```sh
     make install-prod
     ```

   - For development:

     ```sh
     make install-dev
     ```

3. **Build the Plugin:**
   - To create a ZIP file for distribution:

     ```sh
     make release
     ```

### Makefile Commands

- **`make clean`**: Clean the build and dist directories.
- **`make build`**: Build the plugin and create a ZIP file.
- **`make release`**: Install production dependencies and build the plugin.
- **`make install-dev`**: Install development dependencies.
- **`make install-prod`**: Install production dependencies and prefix them into `vendor-prefixed/`.
- **`make update-submodules`**: Update all git submodules.

### Prefixed dependencies

Every third-party library (Google API client, Guzzle, Monolog, psr/*, PHPMailer, firebase/php-jwt) is copied by [Strauss](https://github.com/BrianHenryIE/strauss) into `vendor-prefixed/` under the `DragonGate\Vendor\` namespace, and the plugin loads only `vendor-prefixed/autoload.php`. Another plugin's bundled Guzzle or psr/log can no longer shadow ours (ZYS-2607). `composer install` runs `scripts/prefix-vendor.sh` automatically; `vendor-prefixed/` is generated, not committed, and the release zip ships it without `vendor/`.

Write plugin code against the prefixed names (`DragonGate\Vendor\Google\Client`, `DragonGate\Vendor\Google\Service\Drive`). Only the Drive and Sheets Google services are kept; add another under `extra.google/apiclient-services` in `composer.json`.

### Vendor prefix guard

`mise run vendor-guard` (also run by `mise run foxguard`, `mise run ci`, the CI foxguard job, and the hk pre-push step `vendor-prefix-guard`) keeps the prefixing from regressing. It fails when:

- first-party PHP (`includes/`, `templates/`, `portal-builder.php`, any PHP outside `vendor/`, `vendor-prefixed/`, `node_modules/` and `tests/`) names a bundled dependency without the `DragonGate\Vendor\` prefix: `use GuzzleHttp\Client;`, `new \Monolog\Logger()`, `Psr\Log\LoggerInterface $logger`, `class_exists( 'Google_Client' )`, and the same inside strings. The namespaces come from the production packages in `composer.lock`, so a dependency you add is covered with no edit to the rule;
- `portal-builder.php` or any first-party PHP loads `vendor/autoload.php` instead of `vendor-prefixed/autoload.php`;
- `composer.json` requires a package that is not in `extra.strauss.packages`, or autoloads a tree under `vendor/`;
- (`mise run vendor-guard-built`, part of `mise run ci`) a production package in `composer.lock` is missing from the built `vendor-prefixed/`.

The rule is `rules/unprefixed-vendor-reference.yml.tmpl`, a foxguard rule that `scripts/vendor-guard.mjs` stamps out per namespace and runs; its header explains the exceptions. There is no suppression comment. Fix a finding by prefixing the name (`use DragonGate\Vendor\GuzzleHttp\Client;`; a global `Google_Client` is `DragonGate_Vendor_Google_Client`). A package Strauss must leave unprefixed (a polyfill) goes in `extra.strauss.exclude_from_prefix` in `composer.json`, which the guard reads; say why in the pull request. A trailing comment on a code line is still scanned, so reword it instead of quoting a vendor class name there.

The hk step also runs `tests/unit/vendor-prefix-collision.test.mjs` when `composer.json`, `composer.lock` or `scripts/prefix-vendor.sh` is pushed; that needs `composer install` to have built `vendor-prefixed/`. The guard's own tests are `tests/unit/vendor-guard.test.mjs` with fixtures in `tests/fixtures/vendor-guard/`.

## Contributing

We welcome contributions to the DragonGate Portals plugin! Please follow the guidelines below:

1. Fork the repository.
2. Create a new branch (`git checkout -b feature-branch`).
3. Commit your changes (`git commit -am 'Add new feature'`).
4. Push to the branch (`git push origin feature-branch`).
5. Create a new Pull Request.

Please ensure your code follows the WordPress coding standards and includes appropriate documentation and tests.

## License

View `portal-builder.php` for License information.
