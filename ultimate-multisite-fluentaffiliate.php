<?php
/**
 * Plugin Name: Ultimate Multisite: FluentAffiliate Integration
 * Description: Track recurring commissions in FluentAffiliate for every membership renewal — gateway-agnostic, works with Stripe, PayPal, and all other gateways.
 * Plugin URI: https://multisiteultimate.com/addons
 * Text Domain: ultimate-multisite-fluentaffiliate
 * Version: 1.0.0
 * Author: Multisite Ultimate
 * Author URI: https://multisiteultimate.com
 * Network: true
 * Requires at least: 5.3
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * Requires Plugins: ultimate-multisite
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /lang
 */

// Exit if accessed directly.
defined('ABSPATH') || exit;

// Define addon constants.
define('WP_ULTIMO_FLUENTAFFILIATE_VERSION', '1.0.0');
define('WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_FILE', __FILE__);
define('WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main addon class.
 *
 * @package WP_Ultimo_FluentAffiliate
 * @since 1.0.0
 */
class WP_Ultimo_FluentAffiliate {

	/**
	 * Plugin version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public $version = '1.0.0';

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 * @var WP_Ultimo_FluentAffiliate|null
	 */
	protected static $instance = null;

	/**
	 * Whether the addon has been fully loaded.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	protected $loaded = false;

	/**
	 * Main instance.
	 *
	 * @since 1.0.0
	 * @return WP_Ultimo_FluentAffiliate
	 */
	public static function get_instance() {

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		add_action('plugins_loaded', [$this, 'init'], 11);
	}

	/**
	 * Initialize the addon.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {

		// Check if WP Ultimo / Multisite Ultimate is active.
		if (! class_exists('WP_Ultimo\WP_Ultimo') && ! function_exists('WP_Ultimo')) {
			add_action('admin_notices', [$this, 'wp_ultimo_missing_notice']);
			return;
		}

		// Check if FluentAffiliate is active.
		if (! $this->is_fluent_affiliate_active()) {
			add_action('admin_notices', [$this, 'fluent_affiliate_missing_notice']);
			return;
		}

		// Load plugin files.
		$this->load_dependencies();

		$this->setup_textdomain();

		// Initialize main functionality.
		$this->init_components();

		// Initialize updater.
		$this->init_updater();

		$this->loaded = true;

		/**
		 * Fires when all FluentAffiliate integration dependencies are loaded.
		 *
		 * @since 1.0.0
		 */
		do_action('wu_fluentaffiliate_load');
	}

	/**
	 * Check if FluentAffiliate is active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function is_fluent_affiliate_active() {

		return class_exists('FluentAffiliate\\App\\Models\\Affiliate')
			|| function_exists('fluentAffiliate')
			|| defined('FLUENT_AFFILIATE_PLUGIN_FILE')
			|| function_exists('fluentAffiliatePro');
	}

	/**
	 * Load required dependencies.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies() {

		// Load updater class if not already loaded by another addon.
		if (! class_exists('WP_Ultimo\\Multisite_Ultimate_Updater')) {
			require_once WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR . 'inc/class-multisite-ultimate-updater.php';
		}

		// Load Composer autoloader.
		if (file_exists(WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR . 'vendor/autoload.php')) {
			require_once WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR . 'vendor/autoload.php';
		} else {
			// Manual class loading when no autoloader is present.
			require_once WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR . 'inc/class-fluent-affiliate-helper.php';
			require_once WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_DIR . 'inc/managers/class-fluent-affiliate-manager.php';
		}
	}

	/**
	 * Set up the plugin text domain for translations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setup_textdomain() {

		load_plugin_textdomain(
			'ultimate-multisite-fluentaffiliate',
			false,
			dirname(WP_ULTIMO_FLUENTAFFILIATE_PLUGIN_BASENAME) . '/lang'
		);
	}

	/**
	 * Initialize main components.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_components() {

		WP_Ultimo_FluentAffiliate\Managers\FluentAffiliate_Manager::get_instance()->init();
	}

	/**
	 * Initialize the updater.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_updater() {

		if (class_exists('WP_Ultimo\\Multisite_Ultimate_Updater')) {
			$updater = new \WP_Ultimo\Multisite_Ultimate_Updater('ultimate-multisite-fluentaffiliate', __FILE__);
			$updater->init();
		}
	}

	/**
	 * Returns true if all requirements are met and the addon is loaded.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_loaded() {

		return $this->loaded;
	}

	/**
	 * Display notice when WP Ultimo is not active.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function wp_ultimo_missing_notice() {

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %1$s: Plugin name, %2$s: Multisite Ultimate */
					esc_html__('%1$s requires %2$s to be installed and active.', 'ultimate-multisite-fluentaffiliate'),
					'<strong>' . esc_html__('FluentAffiliate Integration', 'ultimate-multisite-fluentaffiliate') . '</strong>',
					'<strong>' . esc_html__('Multisite Ultimate', 'ultimate-multisite-fluentaffiliate') . '</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display notice when FluentAffiliate is not active.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function fluent_affiliate_missing_notice() {

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %1$s: Plugin name, %2$s: FluentAffiliate */
					esc_html__('%1$s requires %2$s to be installed and active.', 'ultimate-multisite-fluentaffiliate'),
					'<strong>' . esc_html__('FluentAffiliate Integration', 'ultimate-multisite-fluentaffiliate') . '</strong>',
					'<strong><a target="_blank" href="https://fluentaffiliate.com">' . esc_html__('FluentAffiliate', 'ultimate-multisite-fluentaffiliate') . '</a></strong>'
				);
				?>
			</p>
		</div>
		<?php
	}
}

// Initialize the addon.
WP_Ultimo_FluentAffiliate::get_instance();
