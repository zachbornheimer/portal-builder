# DragonGate Portals

DragonGate Portals is a WordPress plugin for building public application forms. Submissions land in Google Sheets and Google Drive — not a WordPress applications inbox.

Created by Z. Bornheimer (ZYSYS).
Learn more at [allintersections.com](https://allintersections.com) or [zysys.org](https://zysys.org)

## Requirements

- **Requires PHP:** 8.0 or higher
- **Requires WordPress:** 6.4 or higher (Requires at least: 6.4)

## First hour: ZIP install to a first test submit

Every control named below is a label that exists in this plugin.

1. **Download the plugin ZIP** from the [Releases](https://github.com/zachbornheimer/portal-builder/releases) page.

2. **Upload and install.** In WordPress admin, go to **Plugins → Add New → Upload Plugin**. Choose the ZIP and click **Install Now**.

3. **Activate.** Click **Activate** to enable DragonGate Portals.

4. **Paste Google API keys.** Open **Portals → Default Settings**. Under **Google API Keys**:
   - Paste the **OAuth client JSON** (Desktop or Web client from Google Cloud) into **Google Secret Key**. This is not a service-account key and not Storage Admin.
   - Paste the **access token** for the Google identity that completed OAuth consent into **Google Access Key**. FileStore refreshes this token.
   - Share the destination Drive folder and Sheet with that same Google identity (the person who completed consent), not a service-account email.

   The full steps live on **Portals → Google API Setup** (**Google API Setup Instructions**).

5. **Add a portal.** Open **Portals → Add New Portal**. The definition wizard opens.

6. **Start.** On **Start**, pick a template (**Composer Prize**, **Call for Scores**, or **Start from scratch**).

7. **Build form.** On **Build form**, add or edit the fields applicants will fill.

8. **Map dests.** On **Map data**, paste each Google Sheet URL and Drive folder. Name each dest. Map every form field to a dest. Sheet column headers = dest names.

9. **Publish.** On **Publish**, set a deadline if you need one, turn on **Accepting submissions**, then click **Save Portal**. The **Public form** URL appears on this step — use **Copy link** or **View public form**.

10. **Send a test submit** through the public form. The row and files appear in the mapped Google Sheet and Google Drive folder. There is no WordPress applications inbox to check.

## Upgrade by ZIP

GitHub Releases (including prereleases) are the update channel. This plugin is not on WordPress.org.

1. **Download the new ZIP** from the [Releases](https://github.com/zachbornheimer/portal-builder/releases) page.

2. **Replace the plugin ZIP.** In WordPress admin, go to **Plugins → Add New → Upload Plugin**. Choose the new ZIP. When WordPress offers to replace the current plugin, accept that. Do not delete the plugin folder or portal posts first.

3. **Activate** if WordPress deactivated the plugin during the replace.

Portal posts and their `_portal_definition` post meta stay in the database. Replacing the plugin files does not delete posts.

## Features

- Definition wizard: **Start** → **Build form** → **Map data** → **Publish**
- Google Sheets for answers, Google Drive for files
- One **Public form** URL per portal
- Site-wide defaults under **Portals → Default Settings**

## Development

Contributor path only. Strangers installing from a ZIP should follow [First hour](#first-hour-zip-install-to-a-first-test-submit).

### Requirements

- **PHP 8.0 or higher**
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
- **`make install-prod`**: Install production dependencies.
- **`make update-submodules`**: Update all git submodules.

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
