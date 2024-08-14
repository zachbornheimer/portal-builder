<?php

if ( ! class_exists( 'Portal_File_Handler' ) ) {

	class Portal_File_Handler {

		private $files;
		private $settings;
		private $anonymize;
		private $local_temp_dir;
		private $appId;
		private $appId_prefix;
		private $post_id;
		public $stored_file_paths;

		public function __construct( $files = null, $settings = array(), $appId_prefix = '', $post_id = null ) {

			$this->files        = $files;
			$this->settings     = $settings;
			$this->appId_prefix = $appId_prefix ?? '';
			$this->post_id      = $post_id;

			$this->anonymize = $this->settings['anonymize'] ?? false;
		}

		public function get_appId() {
			return $this->appId;
		}

		public function set_appId( $id ) {
			$this->appId = $id;
		}

		public function get_temp_file_dir() {
			return $this->local_temp_dir . '/' . $this->appId . '/';
		}

		public function process_temp_files() {

			// if we don't have an $this->appId, create one
			if ( ! isset( $this->appId ) ) {
				$this->appId = $this->create_appId();
			}

			foreach ( array_keys( $this->files ) as $file_key ) {
				$file = $this->files[ $file_key ];

				$this->store_temp_file( $file['type'], $file['tmp_name'], $file_key, $this->maybe_get_file_suffix_from_content( $file_key ) );
			}
		}

		private function maybe_get_file_suffix_from_content( $name ) {

			# get the post's content

			$content = get_post_field( 'post_content', $this->post_id );

			# identify if there's a pb_file shortcode with a file_suffix attribute
			$pattern = '/\[pb_file[^\]]*name=[\'"]' . $name . '[\'"][^\]]*file_suffix=[\'"]([^\'"]+)[\'"][^\]]*\]/s';

			if ( preg_match( $pattern, $content, $match ) ) {
				return $match[1];
			}

			return '';
		}


		public function set_local_temp_dir( $path ) {
			$this->local_temp_dir = $path;

			if ( ! file_exists( $this->local_temp_dir ) ) {
				mkdir( $this->local_temp_dir, 0777, true );
			}
		}

		public function permanently_store_temp() {
			$permanent_dir = $this->settings['permanent_dir'] ?? '';
			if ( $permanent_dir === '' ) {
				throw new Exception( 'Permanent directory not set' );
			}

			$permanent_dir = $permanent_dir . '/' . $this->appId . '/';

			if ( ! file_exists( $permanent_dir ) ) {
				mkdir( $permanent_dir, 0777, true );
			}


			$files = glob( $this->local_temp_dir . '/' . $this->appId . '/*' );

			foreach ( $files as $file ) {
				$file_name     = basename( $file );
				$new_file_path = $permanent_dir . '/' . $file_name;

				if ( ! copy( $file, $new_file_path ) ) {
					throw new Exception( 'Failed to move file to target directory' );
				}
			}
		}

		private function store_temp_file( $type, $tmp_name, $file_key, $suffix = '' ) {
			# if anonymize

			switch ( $type ) {
				case 'application/pdf':
					if ( $this->anonymize ) {
						$this->store_anonymized_pdf( $tmp_name, $file_key, $suffix );
					} else {
						$this->store_pdf( $tmp_name, $file_key, $suffix );
					}
					break;
				case 'audio/mpeg':
					if ( $this->anonymize ) {
						$this->store_anonymized_mp3( $tmp_name, $file_key, $suffix );
					} else {
						$this->store_mp3( $tmp_name, $file_key, $suffix );
					}
					break;
			}
		}

		private function create_appId() {
			$appId = '';

			do {
				$appId = uniqid( $this->appId_prefix );
			} while ( ! mkdir( $this->local_temp_dir . '/' . $appId . '/' ) );

			return $appId;
		}

		private function store_file( $temp_name, $extension, $file_key, $suffix = '' ) {
			$file_name                            = basename( $temp_name );
			$this->stored_file_paths[ $file_key ] = $this->appId . '/' . str_replace( 'php', '', $file_name ) . $suffix . '.' . $extension;
			$new_file_path                        = $this->local_temp_dir . '/' . $this->stored_file_paths[ $file_key ];

			if ( ! move_uploaded_file( $temp_name, $new_file_path ) ) {
				throw new Exception( 'Failed to move file to temp directory' );
			}

			return $new_file_path;
		}

		private function store_pdf( $tmp_name, $file_key, $suffix = '' ) {
			return $this->store_file( $tmp_name, 'pdf', $file_key, $suffix );
		}

		private function store_mp3( $tmp_name, $file_key, $suffix = '' ) {
			return $this->store_file( $tmp_name, 'mp3', $file_key, $suffix );
		}

		private function store_anonymized_pdf( $tmp_name, $file_key, $suffix = '' ) {
			if ( ! $this->isEnabled( 'shell_exec' ) ) {
				throw new Exception( 'shell_exec is disabled' );
			}


			$path   = $this->store_pdf( $tmp_name, $file_key, $suffix );
			$target = escapeshellarg( $path );

			$exiftool_path = $this->binary_tool( 'exiftool' );
			$qpdf_path     = $this->binary_tool( 'qpdf' );

			shell_exec( $exiftool_path . " -overwrite_original -all= $target 2>&1 3>&1" );
			shell_exec( $qpdf_path . " --linearize $target $target.new" );

			# remove any wrapping quotes from target
			$target = trim( $target, "'" );
			$target = trim( $target, '"' );

			if ( ! rename( $target . '.new', $target ) ) {
				throw new Exception( 'Failed to rename anonymized file' );
			}
		}

		private function store_anonymized_mp3( $tmp_name, $file_key, $suffix = '' ) {

			if ( ! $this->isEnabled( 'shell_exec' ) ) {
				throw new Exception( 'shell_exec is disabled' );
			}

			$original_target    = $this->store_mp3( $tmp_name, $file_key, $suffix );
			$original_altTarget = $original_target . '.alt.mp3';
			$target             = escapeshellarg( $original_target );
			$altTarget          = escapeshellarg( $original_altTarget );


			$exiftool_path = $this->binary_tool( 'exiftool' );
			$lame_path     = $this->binary_tool( 'lame' );
			$eyed3_path    = $this->binary_tool( 'eyed3' );
			$perl_path     = $this->binary_tool( 'perl' );

			$existingBitrate = shell_exec( "$exiftool_path -AudioBitrate " . $target . " | $perl_path -lpe 's/.*: (\d+) kbps/$1/' 2>&1" );
			$existingBitrate = rtrim( $existingBitrate );

			if ( filter_var( $existingBitrate, FILTER_VALIDATE_INT ) === false ) {
				$existingBitrate = '256';
			}

			shell_exec( "$lame_path -b " . $existingBitrate . ' ' . $target . ' ' . $altTarget . ' 2>&1' );
			shell_exec( "$eyed3_path --remove-all --remove-all-images --remove-all-comments --remove-all-lyrics " . $altTarget . ' 1>/dev/null 2>/dev/null' );

			if ( ! rename( $original_altTarget, $original_target ) ) {
				throw new Exception( 'Failed to rename anonymized file' );
			}
		}

		private function isEnabled( $func ) {
			return is_callable( $func ) && false === stripos( ini_get( 'disable_functions' ), $func );
		}

		private function binary_tool( $tool ) {

			$path = get_option( 'pb_' . $tool . '_path', '/usr/local/bin/' . $tool );
			$path = escapeshellarg( $path );

			#remove surrounding quotes
			$path = substr( $path, 1, -1 );

			# are these paths valid?
			if ( ! file_exists( $path ) ) {
				throw new Exception( 'Invalid path to ' . $tool );
			}

			return $path;
		}
	}
}
