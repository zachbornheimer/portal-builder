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

						// Custom CSS for the about page - using Tailwind-inspired classes
						$about_css = <<<CSS
						/* About page custom styles */
						#dgp-about {
							font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
						}
						
						#dgp-about * {
							box-sizing: border-box;
						}
						
						/* Layout utilities */
						#dgp-about .wrap { max-width: 1200px; margin: 0 auto; padding: 20px; }
						#dgp-about .grid { display: grid; }
						#dgp-about .grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
						#dgp-about .grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
						#dgp-about .gap-4 { gap: 1rem; }
						#dgp-about .gap-6 { gap: 1.5rem; }
						#dgp-about .gap-2 { gap: 0.5rem; }
						
						/* Flexbox utilities */
						#dgp-about .flex { display: flex; }
						#dgp-about .items-center { align-items: center; }
						#dgp-about .items-start { align-items: flex-start; }
						#dgp-about .justify-between { justify-content: space-between; }
						#dgp-about .gap-4 { gap: 1rem; }
						
						/* Spacing utilities */
						#dgp-about .mb-6 { margin-bottom: 1.5rem; }
						#dgp-about .mb-4 { margin-bottom: 1rem; }
						#dgp-about .mb-3 { margin-bottom: 0.75rem; }
						#dgp-about .mb-2 { margin-bottom: 0.5rem; }
						#dgp-about .mb-1 { margin-bottom: 0.25rem; }
						#dgp-about .mt-8 { margin-top: 2rem; }
						#dgp-about .m-0 { margin: 0; }
						#dgp-about .p-6 { padding: 1.5rem; }
						#dgp-about .p-5 { padding: 1.25rem; }
						#dgp-about .p-4 { padding: 1rem; }
						#dgp-about .px-3 { padding-left: 0.75rem; padding-right: 0.75rem; }
						#dgp-about .py-1 { padding-top: 0.25rem; padding-bottom: 0.25rem; }
						#dgp-about .py-0\.5 { padding-top: 0.125rem; padding-bottom: 0.125rem; }
						#dgp-about .pl-5 { padding-left: 1.25rem; }
						
						/* Border utilities */
						#dgp-about .border { border: 1px solid #e2e8f0; }
						#dgp-about .border-slate-200 { border-color: #e2e8f0; }
						#dgp-about .rounded-2xl { border-radius: 1rem; }
						#dgp-about .rounded-xl { border-radius: 0.75rem; }
						#dgp-about .rounded-full { border-radius: 9999px; }
						#dgp-about .rounded { border-radius: 0.25rem; }
						
						/* Background utilities */
						#dgp-about .bg-white { background-color: #ffffff; }
						#dgp-about .bg-slate-100 { background-color: #f1f5f9; }
						
						/* Shadow utilities */
						#dgp-about .shadow-sm { box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
						
						/* Text utilities */
						#dgp-about .text-2xl { font-size: 1.5rem; line-height: 2rem; }
						#dgp-about .text-lg { font-size: 1.125rem; line-height: 1.75rem; }
						#dgp-about .text-sm { font-size: 0.875rem; line-height: 1.25rem; }
						#dgp-about .text-xs { font-size: 0.75rem; line-height: 1rem; }
						#dgp-about .font-semibold { font-weight: 600; }
						#dgp-about .font-medium { font-weight: 500; }
						#dgp-about .tracking-tight { letter-spacing: -0.025em; }
						#dgp-about .text-slate-900 { color: #0f172a; }
						#dgp-about .text-slate-800 { color: #1e293b; }
						#dgp-about .text-slate-700 { color: #334155; }
						#dgp-about .text-slate-600 { color: #475569; }
						#dgp-about .text-slate-500 { color: #64748b; }
						#dgp-about .text-blue-600 { color: #2563eb; }
						#dgp-about .text-blue-700 { color: #1d4ed8; }
						
						/* List utilities */
						#dgp-about .list-disc { list-style-type: disc; }
						#dgp-about .list-decimal { list-style-type: decimal; }
						
						/* Sizing utilities */
						#dgp-about .h-12 { height: 3rem; }
						#dgp-about .w-12 { width: 3rem; }
						#dgp-about .inline-flex { display: inline-flex; }
						#dgp-about .block { display: block; }
						
						/* Position utilities */
						#dgp-about .relative { position: relative; }
						#dgp-about .ml-auto { margin-left: auto; }
						
						/* Transitions */
						#dgp-about .transition { transition-property: color, background-color, border-color, text-decoration-color, fill, stroke; transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1); transition-duration: 150ms; }
						
						/* Hover states */
						#dgp-about .hover\:border-slate-300:hover { border-color: #cbd5e1; }
						#dgp-about .hover\:text-blue-700:hover { color: #1d4ed8; }
						
						/* Focus states */
						#dgp-about .focus\:outline-none:focus { outline: 2px solid transparent; outline-offset: 2px; }
						#dgp-about .focus\:ring-2:focus { box-shadow: 0 0 0 2px var(--tw-ring-color); }
						#dgp-about .focus\:ring-blue-500:focus { --tw-ring-color: #3b82f6; }
						
						/* Responsive utilities */
						@media (min-width: 768px) {
							#dgp-about .md\:grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
						}
						
						@media (min-width: 1024px) {
							#dgp-about .lg\:grid-cols-3 { grid-template-columns: repeat(3, 1fr); }
							#dgp-about .lg\:col-span-2 { grid-column: span 2 / span 2; }
						}
						
						/* Accessibility */
						@media (prefers-reduced-motion: reduce) {
							#dgp-about * { transition: none !important; animation: none !important; }
						}
						
						/* Links */
						#dgp-about a { text-decoration: none; }
						#dgp-about a:hover { text-decoration: underline; }
						
						/* Code styling */
						#dgp-about code { font-family: ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", Menlo, monospace; }
						CSS;

						wp_register_style( 'dgp-about-custom', false );
						wp_enqueue_style( 'dgp-about-custom' );
						wp_add_inline_style( 'dgp-about-custom', $about_css );
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
