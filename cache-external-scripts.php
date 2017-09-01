<?php
/**
 * Plugin Name: Cache External Scripts
 * Plugin URI: http://www.forcemedia.nl/wordpress-plugins/cache-external-scripts/
 * Description: This plugin allows you to cache the Google Analytics JavaScript file to be cached for more than 2 hours, for a better PageSpeed score
 * Version: 0.5
 * Author: Diego Voors
 * Author URI: http://www.forcemedia.nl
 * License: GPL2
 */

class CacheExternalScripts {
	/**
	 * Absolute path to the WordPRess uploads directory
	 *
	 * @var string
	 */
	private $upload_base_dir;

	/**
	 * URL path to the local uploads directory
	 *
	 * @var string
	 */
	private $upload_base_url;


	/**
	 * Array containing the results of what we've done, for testing and debugging
	 *
	 * @var array
	 */
	private $results = array();

	/**
	 * Initialise the plugin
	 */
	public function __construct() {
		$this->init_vars();
		$this->setup_cron();
		$this->rewrite_html_output();
		add_action( 'wp', array( $this, 'setup_cron' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
		if ( ! is_admin() ) {
			add_filter( 'script_loader_src', array( $this, 'cache_script_loader_src' ) );
		}
		add_action( 'referesh_external_script_cache', array( $this, 'referesh_external_script_cache' ) );
		if ( WP_DEBUG ) {
			add_action( 'wp_footer', array( $this, 'output_debug' ), 999999 );
		}
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate_plugin' ) );
	}

	/**
	 * Setup initial variables which we'll need later
	 */
	public function init_vars() {
		$wp_upload_dir = wp_upload_dir();
		$this->upload_base_dir = $wp_upload_dir['basedir'];
		$this->upload_base_url = $wp_upload_dir['baseurl'];
	}

	/**
	 * Output some the results of our replacements. Trigger this really, relly late
	 * (after the internal_ob_end_flush). Automatically triggered if WP_Debug is enabled.
	 */
	public function output_debug() {
		echo '<!-- BEGIN Cache External Scripts Debug Output -->';
		print_r( $this->results );
		echo '<!-- END Cache External Scripts Debug Output -->';
	}

	/**
	 * Setup the CRON job to refresh the cached files
	 */
	public function setup_cron() {
		if ( ! wp_next_scheduled( 'referesh_external_script_cache' ) ) {
			wp_schedule_event( time(), 'daily', 'referesh_external_script_cache' );
		}
	}

	/**
	 * Rewrite the output HTML to use our local files instead of the remote ones.
	 */
	public function rewrite_html_output() {
		add_action( 'get_header', array( $this, 'internal_ob_start' ) );
		add_action( 'wp_footer', array( $this, 'internal_ob_end_flush' ), 99999 );
	}

	/**
	 * Start our own Output Buffering
	 */
	public function internal_ob_start() {
		return ob_start( array( $this, 'filter_wp_head_output' ) );
	}

	/**
	 * Stop the Output Buffering we started
	 */
	public function internal_ob_end_flush() {
		ob_end_flush();
	}

	/**
	 * The callback for Output Buffering - rewrites the external references with
	 * local ones
	 *
	 * @param string $input The original output.
	 *
	 * @return string The modified output
	 */
	public function filter_wp_head_output( $input ) {
		foreach ( $this->get_scripts_to_cache() as $slug => $script ) {
			if ( $this->script_is_cached( $script ) ) {
				$this->cache_script( $script );
			}

			if ( isset( $script['ob_preg_replace'] ) ) {
				$replacement = str_replace( '{local_url}', $local_url, $script['ob_preg_replace']['replacement'] );
				$output = preg_replace( $script['ob_preg_replace']['pattern'], $replacement, $input );
				if ( $output !== $input ) {
					$this->results[] = 'Replaced: ' . $slug . ' with preg_replace.';
				} else {
					$this->results[] = 'Did not replace: ' . $slug . ' with preg_replace.';
				}
				$input = $output;
			}

			if ( isset( $script['ob_str_replace'] ) ) {
				$replacement = str_replace( '{local_url}', $local_url, $script['ob_str_replace']['replacement'] );
				$output = str_replace( $script['ob_str_replace']['pattern'], $replacement, $input );
				if ( $output !== $input ) {
					$this->results[] = 'Replaced: ' . $slug . ' with str_replace.';
				} else {
					$this->results[] = 'Did not replace: ' . $slug . ' with str_replace.';
				}
				$input = $output;
			}
		}
		return $input;
	}

	/**
	 * Given a script, check whether it's been cached
	 *
	 * @param  array $script Array including the key 'basename'.
	 *
	 * @return boolean Returns true if the file has been cached, or false if not.
	 */
	public function script_is_cached( $script ) {
		return file_exists( $this->get_cached_script_path( $script ) );
	}

	/**
	 * Get the path which should point to a cached script (whether it's been cached
	 * locally or not)
	 *
	 * @param  array $script Array including the key 'basename'.
	 *
	 * @return string absolute path.
	 */
	public function get_cached_script_path( $script ) {
		return $this->upload_base_dir . '/cached-scripts/' . $this->get_script_basename( $script );
	}

	/**
	 * Get the path which should point to a cached script (whether it's been cached
	 * locally or not)
	 *
	 * @param  array $script Array including the key 'basename'.
	 *
	 * @return string absolute path.
	 */
	public function get_cached_script_url( $script ) {
		return $this->upload_base_url . '/cached-scripts/' . $this->get_script_basename( $script );
	}

	/**
	 * Given a script, check whether it's been cached
	 *
	 * @param array $script Array including the key 'basename' atleast 'external_url'.
	 *
	 * @return boolean Returns true if the file was successfully cached
	 */
	public function cache_script( $script ) {
		$dir = $this->upload_base_dir . '/cached-scripts';
		if ( ! file_exists( $dir ) && ! is_dir( $dir ) ) {
			mkdir( $dir );
		}

		$remote_data = $this->get_data( $script['external_url'] );
		if ( ! $remote_data ) {
			return false;
		}
		$success = file_put_contents(
			$this->get_cached_script_path( $script ),
			$remote_data
		);
		return $success !== false;
	}

	/**
	 * Update the locally cached files
	 */
	public function referesh_external_script_cache() {
		foreach ( $this->get_scripts_to_cache() as $index => $script ) {
			$this->cache_script( $script );
		}
	}

	/**
	 * Filters script_loader_src and replaces external script URLs with locally
	 * cached ones if requried.
	 *
	 * @param string $src Script URL
	 *
	 * @return string Script URL - either the original or the new, local one.
	 */
	public function cache_script_loader_src( $src ) {
		if ( $this->src_should_be_cached( $src ) ) {
			// Since we only have the URL here, create a mini mock
			// script for our script loving objects.
			$script = array(
				'external_url' => $src,
				'basename' => $this->get_script_basename( $src ),
			);
			$this->cache_script( $script );
			return $this->get_cached_script_url( $script );
		}
		return $src;
	}


	/**
	 * Given a URL, see if it's one which we should cache locally. This is for
	 * processing URLs added with wp_enqueue_script
	 *
	 * @param string $src The URL to check.
	 *
	 * @return boolean True if we should be caching this URL, false otherwise.
	 */
	public function src_should_be_cached( $src ) {
		// We only cache absolute URLs.
		if ( substr( $src, 0, 4 ) !== 'http' ) {
			return false;
		}
		foreach ( $this->get_scripts_to_cache() as $script ) {
			if ( isset( $script['script_loader_src_regex'] ) ) {
				if ( preg_match( $script['script_loader_src_regex'], $src ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get a list of scripts which need caching, along with useful information about them.
	 *
	 * @return array Assosiative array of scripts to cache with a slug as an index and
	 *               another array with bits of information as the values.
	 */
	public function get_scripts_to_cache() {
		$defaults = array(
			'google-legacy-analytics' => array(
				'external_url' => 'http://www.google-analytics.com/ga.js',
				'ob_str_replace' => array(
					'pattern' => "ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';",
					'replacement' => "ga.src = '{local_url}'",
				),
			),
			'google-universal-analytics' => array(
				'external_url' => 'http://www.google-analytics.com/analytics.js',
				'ob_preg_replace' => array(
					'pattern' => '#(http:|https:|)//www.google-analytics.com/analytics.js#',
					'replacement' => '{local_url}',
				),
			),
			'share_this' => array(
				'script_loader_src_regex' => '#sharethis.com#',
			),
		);
		$scripts = apply_filters( 'cache_external_scripts_list', $defaults );
		return $scripts;
	}

	/**
	 * Calculate the basename for a script based on as much info as possible.
	 *
	 * @param mixed $script Array containing as many keys as possible to identiy
	 *                      the script. The $script is read by reference and a
	 *                      basename key will be added if it doesn't exist. If a
	 *                      string is given then a basename will be returned as
	 *                      as string.
	 *
	 *
	 * @return string The basename for the script (e.g. analytics.js )
	 */
	public function get_script_basename( &$script, $slug = false ) {
		if ( isset( $script['basename'] ) ) {
			return $script['basename'];
		}
		// If we've been given a string, turn it into an array and call
		// ourself.
		if ( is_string($script) ) {
			$script = array(
				'external_url' => $script,
			);
			return $this->get_script_basename($script);
		}

		// Bit of a long one here. If we have an external_url, and are able to parse to get
		// a path out of it - then use that path.
		if (
			isset( $script['external_url'] )
			&& ! empty( $path = wp_parse_url( $script['external_url'], PHP_URL_PATH ) )
			&& ! empty ( $basename = basename( $path ) )
		) {
			$script['basename'] = $basename;
		} elseif ( ! empty( $slug ) ) {
			$script['basename'] = $slug . '.js';
		} else {
			$script['basename'] =  crc32( $script['external_url'] ) . '.js';
		}
		return $script['basename'];
	}

	/**
	 * Add our Admin Menu
	 */
	public function add_admin_menu() {
		add_options_page( 'Cache External Scripts', 'Cache External Scripts', 'manage_options', 'cache-external-scripts', array( $this, 'options_page' ) );
	}

	/**
	 * Register our settings
	 */
	public function settings_init() {
		register_setting( 'pluginPage', 'ces_settings', 'validate_input' );
	}

	/**
	 * Output our Options page
	 */
	function options_page() {
		?>
			<h1>Cache External Sources</h1>
		<?php
		if ( 'cache-scripts' === $_GET['action'] ) {
			echo '<p>Fetching scripts...';
			$this->referesh_external_script_cache();
			echo 'done</p>';
		}
		echo '<ul>';
		foreach ( $this->get_scripts_to_cache() as $script ) {
			echo '<li>';
			echo esc_html( $this->get_script_basename( $script ) ) . ' : ';
			if ( $this->script_is_cached( $script ) ) {
				$time_since_update = time() - filemtime( $this->get_cached_script_path( $script ) );
				echo 'Last Updated: ' . esc_html( round( $time_since_update / 60) ) . ' minutes ago';
			} else {
				echo 'Not Cached';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p>In case you want to force the cache to be renewed, click';
	 	echo ' <a href="' . esc_attr( get_site_url() ) . '/wp-admin/options-general.php?page=cache-external-scripts&action=cache-scripts">';
		echo 'this link</a>';
	}



	/**
	 * Get the data from a URL
	 *
	 * @param string $url Absolute URL to get the data from.
	 *
	 * @return text The contents of the file
	 */
	static function get_data( $url ) {
		$ch = curl_init();
		$timeout = 5;
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
		$data = curl_exec( $ch );
		curl_close( $ch );
		return $data;
	}

	/**
	 * When the plugin is deactivated, remove the cron job and tidy up.
	 */
	public static function deactivate_plugin() {
		// find out when the last event was scheduled.
		$timestamp = wp_next_scheduled( 'referesh_external_script_cache' );
		// unschedule previous event if any.
		wp_unschedule_event( $timestamp, 'referesh_external_script_cache' );
	}
}
new CacheExternalScripts;
