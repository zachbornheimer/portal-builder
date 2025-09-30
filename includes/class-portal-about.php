<?php

if ( ! class_exists( 'Portal_About' ) ) {

	/**
	 * Portal_About – Registers and renders a beautiful Tailwind-powered "About DragonGate Portals" admin screen.
	 *
	 * Responsibilities:
	 * - Creates an about page under the Portals menu
	 * - Provides plugin information, features, and developer resources
	 * - Uses Tailwind CSS for modern, responsive design
	 *
	 * @component
	 * @see Portal_Settings, Portal_Post_Type
	 */
	class Portal_About {

		/**
		 * Initialize the about page functionality.
		 *
		 * @return void
		 */
		public function init() {
			add_action( 'admin_menu', array( $this, 'register_about_screen' ), 20 );
		}

		/**
		 * Add "About" under the DragonGate/Portal Builder menu.
		 *
		 * @return void
		 */
		public function register_about_screen(): void {
			// Attach About beneath the portal post type menu
			$parent_slug = 'edit.php?post_type=portal';

			$hook = add_submenu_page(
				$parent_slug,
				__( 'About DragonGate Portals', 'dragongate-portals' ),
				__( 'About', 'dragongate-portals' ),
				'manage_options',
				'dgp-about',
				array( $this, 'render_about_screen' ),
				99
			);

			// Enqueue assets only on this screen.
			if ( $hook ) {
				add_action( "load-{$hook}", function () use ( $hook ) {
					add_action( 'admin_enqueue_scripts', function () use ( $hook ) {
						$screen = get_current_screen();
						if ( ! $screen || $screen->id !== $hook ) {
							return;
						}

						// Tailwind via CDN – for production, consider bundling a trimmed build.
						wp_enqueue_style(
							'dgp-tailwind',
							'https://cdn.jsdelivr.net/npm/tailwindcss@3.4.14/dist/tailwind.min.css',
							array(),
							'3.4.14'
						);

						// Scope helpers to avoid collisions with WP admin and plugins.
						$scoped_css = <<<CSS
						/* Scope all Tailwind utility usage under #dgp-about */
						#dgp-about * { box-sizing: border-box; }
						#dgp-about .prose a { text-decoration: underline; }
						@media (prefers-reduced-motion: reduce) {
						  #dgp-about * { transition: none !important; animation: none !important; }
						}
						CSS;

						wp_register_style( 'dgp-about-inline', false );
						wp_enqueue_style( 'dgp-about-inline' );
						wp_add_inline_style( 'dgp-about-inline', $scoped_css );
					} );
				} );
			}
		}

		/**
		 * Render the About screen.
		 *
		 * @return void
		 */
		public function render_about_screen(): void {
			// Get plugin version from main plugin file
			$plugin_data = get_file_data( plugin_dir_path( __FILE__ ) . '../portal-builder.php', array( 'Version' => 'Version' ) );
			$plugin_version = $plugin_data['Version'] ?: '0.0.3a';
			$icon_url       = plugin_dir_url( __FILE__ ) . '../assets/icon.svg';
			$site_alli      = 'https://allintersections.com';
			$site_zysys     = 'https://zysys.org';
			$repo_releases  = 'https://github.com/zachbornheimer/portal-builder/releases';
			?>
			<div id="dgp-about" class="wrap">
				<!-- Header: brand + title -->
				<section class="relative isolate mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
					<div class="flex items-center gap-4">
						<img src="<?php echo esc_url( $icon_url ); ?>" alt="" width="48" height="48" class="h-12 w-12" />
						<div>
							<h1 class="m-0 text-2xl font-semibold tracking-tight text-slate-900">About DragonGate Portals</h1>
							<p class="m-0 text-sm text-slate-500">Build submission portals that sync with Google Drive &amp; Google Sheets.</p>
						</div>
						<span class="ml-auto inline-flex items-center rounded-full border border-slate-200 px-3 py-1 text-xs font-medium text-slate-600">v<?php echo esc_html( $plugin_version ); ?></span>
					</div>
				</section>

				<!-- Key actions -->
				<section class="mb-6 grid gap-4 md:grid-cols-3">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=portal' ) ); ?>" class="group block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
						<h2 class="mb-1 text-sm font-semibold text-slate-900">Open Portal Builder</h2>
						<p class="m-0 text-sm text-slate-600">Create or manage a portal.</p>
					</a>
					<a href="<?php echo esc_url( $repo_releases ); ?>" target="_blank" rel="noopener noreferrer" class="group block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
						<h2 class="mb-1 text-sm font-semibold text-slate-900">Releases</h2>
						<p class="m-0 text-sm text-slate-600">Download the latest build from GitHub.</p>
					</a>
					<a href="<?php echo esc_url( $site_alli ); ?>" target="_blank" rel="noopener noreferrer" class="group block rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500">
						<h2 class="mb-1 text-sm font-semibold text-slate-900">Documentation</h2>
						<p class="m-0 text-sm text-slate-600">Learn more at allintersections.com.</p>
					</a>
				</section>

				<!-- Content grid -->
				<section class="grid gap-6 lg:grid-cols-3">
					<!-- Features -->
					<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
						<h3 class="mb-4 text-lg font-semibold text-slate-900">Features</h3>
						<ul class="grid list-disc gap-2 pl-5 text-sm text-slate-700">
							<li><strong>Portal Builder:</strong> Create intake portals for any file/application type.</li>
							<li><strong>Google Sheets Integration:</strong> Store submission metadata in Sheets.</li>
							<li><strong>Google Drive Integration:</strong> Backup and organize uploaded files in Drive.</li>
							<li><strong>Custom Post Types &amp; Meta:</strong> Tailor forms and workflows to your needs.</li>
							<li><strong>Admin UX:</strong> Clean, simple management for reviewers and admins.</li>
							<li><strong>Privacy-first:</strong> Transparent storage + clear permissions.</li>
						</ul>
					</div>

					<!-- Quick info -->
					<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
						<h3 class="mb-4 text-lg font-semibold text-slate-900">At a Glance</h3>
						<dl class="space-y-3 text-sm">
							<div class="flex items-start justify-between gap-4">
								<dt class="text-slate-500">Requires</dt>
								<dd class="text-slate-800">PHP 8.x+</dd>
							</div>
							<div class="flex items-start justify-between gap-4">
								<dt class="text-slate-500">Integrations</dt>
								<dd class="text-slate-800">Google Drive, Google Sheets</dd>
							</div>
							<div class="flex items-start justify-between gap-4">
								<dt class="text-slate-500">Author</dt>
								<dd class="text-slate-800">Z. Bornheimer (ZYSYS)</dd>
							</div>
							<div class="flex items-start justify-between gap-4">
								<dt class="text-slate-500">Links</dt>
								<dd class="text-slate-800">
									<a class="text-blue-600 hover:text-blue-700" href="<?php echo esc_url( $site_alli ); ?>" target="_blank" rel="noopener noreferrer">allintersections.com</a>
									<span aria-hidden="true"> · </span>
									<a class="text-blue-600 hover:text-blue-700" href="<?php echo esc_url( $site_zysys ); ?>" target="_blank" rel="noopener noreferrer">zysys.org</a>
								</dd>
							</div>
						</dl>
					</div>

					<!-- How it works -->
					<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
						<h3 class="mb-4 text-lg font-semibold text-slate-900">How it Works</h3>
						<ol class="grid list-decimal gap-2 pl-5 text-sm text-slate-700">
							<li>Create a portal in <em>Portals → Add New</em>.</li>
							<li>Connect Google Sheets/Drive in the portal settings.</li>
							<li>Publish the portal and share the front-end link.</li>
							<li>Applicants submit files/forms; admins review in WP.</li>
							<li>Data syncs to Sheets; files are backed up to Drive.</li>
						</ol>
					</div>

					<!-- Privacy -->
					<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
						<h3 class="mb-2 text-lg font-semibold text-slate-900">Privacy &amp; Security</h3>
						<p class="mb-3 text-sm text-slate-700">
							DragonGate Portals minimizes stored personal data and uses explicit Google OAuth scopes for Drive/Sheets access.
						</p>
						<ul class="list-disc pl-5 text-sm text-slate-700">
							<li>Clear data retention and export paths.</li>
							<li>Scoped permissions; no broad account access.</li>
							<li>WP roles/capabilities gate reviewer access.</li>
						</ul>
					</div>

					<!-- Dev -->
					<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
						<h3 class="mb-4 text-lg font-semibold text-slate-900">Developer Notes</h3>
						<ul class="grid list-disc gap-2 pl-5 text-sm text-slate-700">
							<li><strong>Install:</strong> <code class="rounded bg-slate-100 px-1 py-0.5">make install-prod</code> · <strong>Dev:</strong> <code class="rounded bg-slate-100 px-1 py-0.5">make install-dev</code></li>
							<li><strong>Build Release:</strong> <code class="rounded bg-slate-100 px-1 py-0.5">make release</code></li>
							<li><strong>Submodules:</strong> <code class="rounded bg-slate-100 px-1 py-0.5">make update-submodules</code></li>
							<li>Follows WP coding standards; PHP 8.x+.</li>
						</ul>
					</div>
				</section>

				<!-- Footer -->
				<footer class="mt-8 rounded-2xl border border-slate-200 bg-white p-4 text-center text-xs text-slate-500">
					<span>© <?php echo esc_html( date( 'Y' ) ); ?> DragonGate Portals · Created by Z. Bornheimer (ZYSYS)</span>
				</footer>
			</div>
			<?php
		}
	}

}
