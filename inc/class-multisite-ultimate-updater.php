<?php
/**
 * Updates add-ons
 *
 * @package WP_Ultimo_FluentAffiliate
 * @subpackage Updater
 * @since 1.0.0
 */

namespace WP_Ultimo;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * Updates add-ons from the main site.
 *
 * @since 1.0.0
 */
class Multisite_Ultimate_Updater {

	/**
	 * Plugin slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Plugin file path.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $plugin_slug Slug of the plugin.
	 * @param string $plugin_file Main file of the plugin.
	 */
	public function __construct(string $plugin_slug, string $plugin_file) {

		$this->plugin_slug = $plugin_slug;
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Add the main hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {

		add_action('init', [$this, 'enable_auto_updates']);
	}

	/**
	 * Adds the auto-update hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enable_auto_updates() {

		if (! defined('MULTISITE_ULTIMATE_UPDATE_URL')) {
			define('MULTISITE_ULTIMATE_UPDATE_URL', 'https://ultimatemultisite.com/');
		}

		$url = add_query_arg(
			[
				'update_slug'   => $this->plugin_slug,
				'update_action' => 'get_metadata',
			],
			MULTISITE_ULTIMATE_UPDATE_URL
		);

		if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
			\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
				$url,
				$this->plugin_file,
				$this->plugin_slug
			);
		}
	}
}
