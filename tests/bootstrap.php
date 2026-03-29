<?php
/**
 * PHPUnit bootstrap file for multisite-ultimate-fluentaffiliate addon.
 *
 * @package WP_Ultimo_FluentAffiliate
 */

$_tests_dir = getenv('WP_TESTS_DIR');

// Load PHPUnit Polyfills.
if (file_exists(dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php')) {
	require_once dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
}

if (! $_tests_dir) {
	$_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if (false !== $_phpunit_polyfills_path) {
	define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path);
}

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit(1);
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the main Multisite Ultimate plugin (required dependency).
 */
function _manually_load_main_plugin() {
	// Load the main Multisite Ultimate plugin first.
	$main_plugin = dirname(dirname(dirname(__DIR__))) . '/ultimate-multisite/ultimate-multisite.php';
	if (file_exists($main_plugin)) {
		require $main_plugin;
	}
}

/**
 * Manually load the addon being tested.
 */
function _manually_load_addon() {
	require dirname(__DIR__) . '/ultimate-multisite-fluentaffiliate.php';
}

// Load main plugin first, then addon.
tests_add_filter('muplugins_loaded', '_manually_load_main_plugin');
tests_add_filter('plugins_loaded', '_manually_load_addon', 11);

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
