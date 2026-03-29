<?php
/**
 * FluentAffiliate Helper — API compatibility layer.
 *
 * Provides a stable interface for interacting with FluentAffiliate across
 * plugin versions. Attempts multiple API methods in order of preference.
 *
 * Verified API surface (FluentAffiliate 1.x):
 *   - FluentAffiliate\App\Models\Affiliate  — Eloquent-style model
 *   - FluentAffiliate\App\Models\Commission — Eloquent-style model
 *   - FluentAffiliate\App\Services\CommissionService — service class
 *   - fluentAffiliate()                     — main plugin helper function
 *   - FLUENT_AFFILIATE_PLUGIN_FILE          — plugin constant
 *
 * @package WP_Ultimo_FluentAffiliate
 * @subpackage Helpers
 * @since 1.0.0
 */

namespace WP_Ultimo_FluentAffiliate;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * FluentAffiliate Helper Class.
 *
 * @since 1.0.0
 */
class FluentAffiliate_Helper {

	/**
	 * Check if FluentAffiliate is active and available.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_active() {

		return class_exists('FluentAffiliate\\App\\Models\\Affiliate')
			|| function_exists('fluentAffiliate')
			|| defined('FLUENT_AFFILIATE_PLUGIN_FILE')
			|| function_exists('fluentAffiliatePro');
	}

	/**
	 * Validate that an affiliate ID is active.
	 *
	 * @since 1.0.0
	 * @param int $affiliate_id Affiliate ID to validate.
	 * @return bool True if the affiliate exists and is active.
	 */
	public static function validate_affiliate($affiliate_id) {

		$affiliate_id = intval($affiliate_id);

		if ($affiliate_id <= 0) {
			return false;
		}

		if (class_exists('FluentAffiliate\\App\\Models\\Affiliate')) {
			try {
				$affiliate = \FluentAffiliate\App\Models\Affiliate::find($affiliate_id);
				return $affiliate && 'active' === $affiliate->status;
			} catch (\Exception $e) {
				self::log('Affiliate validation error: ' . $e->getMessage(), ['affiliate_id' => $affiliate_id]);
				return false;
			}
		}

		// Fallback: allow custom validation via filter.
		return (bool) apply_filters('wu_fluentaffiliate_validate_affiliate', true, $affiliate_id);
	}

	/**
	 * Get affiliate data by ID.
	 *
	 * @since 1.0.0
	 * @param int $affiliate_id Affiliate ID.
	 * @return array|false Affiliate data array or false if not found.
	 */
	public static function get_affiliate($affiliate_id) {

		$affiliate_id = intval($affiliate_id);

		if (! self::validate_affiliate($affiliate_id)) {
			return false;
		}

		if (class_exists('FluentAffiliate\\App\\Models\\Affiliate')) {
			try {
				$affiliate = \FluentAffiliate\App\Models\Affiliate::find($affiliate_id);

				if ($affiliate) {
					$user_details = $affiliate->user_details ?? [];
					$name         = $user_details['full_name'] ?? ($user_details['email'] ?? '');
					$email        = $user_details['email'] ?? ($affiliate->email ?? '');

					return [
						'id'              => $affiliate->id,
						'name'            => $name ?: $email,
						'email'           => $email,
						'status'          => $affiliate->status,
						'commission_rate' => $affiliate->commission_rate ?? 0,
					];
				}
			} catch (\Exception $e) {
				self::log('Get affiliate error: ' . $e->getMessage(), ['affiliate_id' => $affiliate_id]);
			}
		}

		return false;
	}

	/**
	 * Get all active affiliates for use in admin dropdowns.
	 *
	 * @since 1.0.0
	 * @return array Associative array of affiliate_id => display_name.
	 */
	public static function get_active_affiliates() {

		$affiliates = ['' => __('No affiliate assigned', 'ultimate-multisite-fluentaffiliate')];

		if (! self::is_active()) {
			return $affiliates;
		}

		if (class_exists('FluentAffiliate\\App\\Models\\Affiliate')) {
			try {
				$records = \FluentAffiliate\App\Models\Affiliate::where('status', 'active')->get();

				foreach ($records as $affiliate) {
					$user_details = $affiliate->user_details ?? [];
					$name         = $user_details['full_name'] ?? '';
					$email        = $user_details['email'] ?? ($affiliate->email ?? '');
					$display      = $name ? sprintf('%s (%s)', $name, $email) : $email;

					$affiliates[ $affiliate->id ] = $display;
				}
			} catch (\Exception $e) {
				self::log('Failed to fetch affiliates for dropdown: ' . $e->getMessage());
			}
		}

		return apply_filters('wu_fluentaffiliate_available_affiliates', $affiliates);
	}

	/**
	 * Create a commission record in FluentAffiliate.
	 *
	 * Attempts multiple API methods in order:
	 *   1. fluentAffiliate()->createCommission()
	 *   2. CommissionService::create()
	 *   3. Commission::create() (direct model)
	 *
	 * @since 1.0.0
	 * @param array $commission_data {
	 *     Commission data.
	 *
	 *     @type int    $affiliate_id   Required. FluentAffiliate affiliate ID.
	 *     @type float  $amount         Required. Commission amount.
	 *     @type int    $reference_id   Required. WP Ultimo payment ID.
	 *     @type string $reference_type Required. Fixed as 'wu_payment'.
	 *     @type string $currency       Optional. Defaults to site currency.
	 *     @type string $description    Optional. Human-readable description.
	 *     @type string $customer_email Optional. Customer email.
	 *     @type string $customer_name  Optional. Customer display name.
	 *     @type int    $membership_id  Optional. Associated membership ID.
	 *     @type string $type           Optional. 'payment' or 'renewal'.
	 * }
	 * @return int|false Commission ID on success, false on failure.
	 */
	public static function create_commission($commission_data) {

		// Validate required fields.
		$required = ['affiliate_id', 'amount', 'reference_id', 'reference_type'];
		foreach ($required as $field) {
			if (empty($commission_data[ $field ])) {
				self::log('Missing required commission field: ' . $field, $commission_data);
				return false;
			}
		}

		if (! self::validate_affiliate($commission_data['affiliate_id'])) {
			self::log('Invalid or inactive affiliate', ['affiliate_id' => $commission_data['affiliate_id']]);
			return false;
		}

		// Apply defaults.
		$commission_data = array_merge(
			[
				'status'      => 'pending',
				'currency'    => function_exists('wu_get_currency') ? wu_get_currency() : 'USD',
				'description' => '',
				'created_at'  => current_time('mysql'),
			],
			$commission_data
		);

		$commission_id = false;

		// Method 1: Direct FluentAffiliate API function.
		if (function_exists('fluentAffiliate') && method_exists(fluentAffiliate(), 'createCommission')) {
			try {
				$commission_id = fluentAffiliate()->createCommission($commission_data);
			} catch (\Exception $e) {
				self::log('fluentAffiliate()->createCommission() failed: ' . $e->getMessage());
			}
		}

		// Method 2: CommissionService.
		if (! $commission_id && class_exists('FluentAffiliate\\App\\Services\\CommissionService')) {
			try {
				$service = new \FluentAffiliate\App\Services\CommissionService();
				if (method_exists($service, 'create')) {
					$commission_id = $service->create($commission_data);
				}
			} catch (\Exception $e) {
				self::log('CommissionService::create() failed: ' . $e->getMessage());
			}
		}

		// Method 3: Direct model insertion.
		if (! $commission_id && class_exists('FluentAffiliate\\App\\Models\\Commission')) {
			try {
				$commission    = \FluentAffiliate\App\Models\Commission::create($commission_data);
				$commission_id = $commission->id ?? false;
			} catch (\Exception $e) {
				self::log('Commission::create() failed: ' . $e->getMessage());
			}
		}

		if ($commission_id) {
			self::log(
				'Commission created',
				[
					'commission_id' => $commission_id,
					'affiliate_id'  => $commission_data['affiliate_id'],
					'amount'        => $commission_data['amount'],
					'type'          => $commission_data['type'] ?? 'payment',
				]
			);

			/**
			 * Fires after a FluentAffiliate commission is successfully created.
			 *
			 * @since 1.0.0
			 * @param int   $commission_id   The new commission ID.
			 * @param array $commission_data The commission data used.
			 */
			do_action('wu_fluentaffiliate_commission_created', $commission_id, $commission_data);
		} else {
			self::log('All commission creation methods failed', $commission_data);
		}

		return $commission_id;
	}

	/**
	 * Get a commission by payment reference.
	 *
	 * @since 1.0.0
	 * @param int    $reference_id   Payment ID.
	 * @param string $reference_type Reference type (e.g. 'wu_payment').
	 * @return array|false Commission data array or false.
	 */
	public static function get_commission_by_reference($reference_id, $reference_type) {

		if (class_exists('FluentAffiliate\\App\\Models\\Commission')) {
			try {
				$commission = \FluentAffiliate\App\Models\Commission::where('reference_id', $reference_id)
					->where('reference_type', $reference_type)
					->first();

				if ($commission) {
					return $commission->toArray();
				}
			} catch (\Exception $e) {
				self::log('get_commission_by_reference error: ' . $e->getMessage());
			}
		}

		return false;
	}

	/**
	 * Update a commission's status.
	 *
	 * @since 1.0.0
	 * @param int    $commission_id Commission ID.
	 * @param string $status        New status: pending|approved|paid|cancelled|refunded.
	 * @return bool True on success.
	 */
	public static function update_commission_status($commission_id, $status) {

		$commission_id = intval($commission_id);

		if ($commission_id <= 0) {
			return false;
		}

		$valid_statuses = ['pending', 'approved', 'paid', 'cancelled', 'refunded'];
		if (! in_array($status, $valid_statuses, true)) {
			return false;
		}

		$success = false;

		// Method 1: Direct FluentAffiliate API.
		if (function_exists('fluentAffiliate') && method_exists(fluentAffiliate(), 'updateCommission')) {
			try {
				$success = (bool) fluentAffiliate()->updateCommission($commission_id, ['status' => $status]);
			} catch (\Exception $e) {
				self::log('fluentAffiliate()->updateCommission() failed: ' . $e->getMessage());
			}
		}

		// Method 2: Direct model update.
		if (! $success && class_exists('FluentAffiliate\\App\\Models\\Commission')) {
			try {
				$commission = \FluentAffiliate\App\Models\Commission::find($commission_id);
				if ($commission) {
					$commission->status     = $status;
					$commission->updated_at = current_time('mysql');
					$success                = (bool) $commission->save();
				}
			} catch (\Exception $e) {
				self::log('Commission::save() failed: ' . $e->getMessage());
			}
		}

		if ($success) {
			do_action('wu_fluentaffiliate_commission_status_updated', $commission_id, $status);
		}

		return $success;
	}

	/**
	 * Log a debug message.
	 *
	 * Only writes when WP_DEBUG is enabled. Also fires an action for custom loggers.
	 *
	 * @since 1.0.0
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 * @return void
	 */
	public static function log($message, $context = []) {

		if (defined('WP_DEBUG') && WP_DEBUG) {
			$log_entry = '[FluentAffiliate Integration] ' . $message;

			if (! empty($context)) {
				$log_entry .= ' | Context: ' . wp_json_encode($context);
			}

			error_log($log_entry); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		do_action('wu_fluentaffiliate_log', $message, $context);
	}
}
