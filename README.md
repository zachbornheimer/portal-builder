Here's the updated README with the PHP 8 requirement:

---

# Portal Builder

Portal Builder is a WordPress plugin designed to create and manage portals for accepting applications and managing submissions with Google Sheets and Google Drive integration.

Created by Z. Bornheimer (ZYSYS).
Learn more at [allintersections.com](https://allintersections.com) or [zysys.org](https://zysys.org)

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Development](#development)
  - [Requirements](#requirements)
  - [Build Process](#build-process)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Application Management:** Easily create and manage applications.
- **Google Sheets Integration:** Store application data in Google Sheets.
- **Google Drive Integration:** Backup files to Google Drive.
- **Custom Post Types:** Create and manage custom post types.
- **User-friendly Interface:** Simple and intuitive admin interface.

## Installation

1. **Download the Plugin:**

   - Download the latest release from the [Releases](https://github.com/zachbornheimer/portal-builder/releases) page.

2. **Upload to WordPress:**

   - Navigate to `Plugins > Add New > Upload Plugin`.
   - Select the downloaded ZIP file and click `Install Now`.

3. **Activate the Plugin:**
   - After installation, click `Activate` to enable the Portal Builder plugin.

## Usage

1. **Create a Portal:**

   - Navigate to the `Portals` menu in the WordPress admin.
   - Click `Add New` to create a new portal.
   - Fill in the required details, including integration settings for Google Sheets and Google Drive.

2. **Manage Applications:**

   - Once a portal is created, applications can be submitted through the front-end.
   - View and manage submissions from the WordPress admin panel.

3. **Custom Meta Fields:**
   - Use the built-in custom meta fields to tailor application forms to your needs.

## Development

### Requirements

- **PHP 8.x or higher**
- **Composer**
- **Node.js (Optional, for asset building)**

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

We welcome contributions to the Portal Builder plugin! Please follow the guidelines below:

1. Fork the repository.
2. Create a new branch (`git checkout -b feature-branch`).
3. Commit your changes (`git commit -am 'Add new feature'`).
4. Push to the branch (`git push origin feature-branch`).
5. Create a new Pull Request.

Please ensure your code follows the WordPress coding standards and includes appropriate documentation and tests.

## License

View `portal-builder.php` for License information.

This updated README reflects that PHP 8.x is required for this project. You can place this in the root directory of your repository as `README.md`.
