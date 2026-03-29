<?php
/**
 * FluentAffiliate Manager — commission tracking and admin UI.
 *
 * Hooks into Multisite Ultimate payment and membership events to:
 *   1. Capture affiliate ID from FluentAffiliate cookie/session at signup.
 *   2. Store affiliate ID on the membership model for future renewals.
 *   3. Fire a commission on every completed payment (gateway-agnostic).
 *   4. Provide a manual affiliate assignment field on the membership edit page.
 *
 * Hook strategy (gateway-agnostic):
 *   - `wu_payment_post_save` fires after every payment is persisted, regardless
 *     of gateway. We inspect `transaction_type` to distinguish initial vs renewal.
 *   - This mirrors the AffiliateWP addon pattern and avoids coupling to any
 *     specific gateway (Stripe, PayPal, Manual, Free).
 *
 * @package WP_Ultimo_FluentAffiliate
 * @subpackage Managers
 * @since 1.0.0
 */

namespace WP_Ultimo_FluentAffiliate\Managers;

use WP_Ultimo_FluentAffiliate\FluentAffiliate_Helper;

// Exit if accessed directly.
defined('ABSPATH') || exit;

/**
 * FluentAffiliate Manager class.
 *
 * @since 1.0.0
 */
class FluentAffiliate_Manager {

	/**
	 * Meta key used to store the affiliate ID on a membership.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_AFFILIATE_ID = 'fluent_affiliate_id';

	/**
	 * Single instance.
	 *
	 * @since 1.0.0
	 * @var FluentAffiliate_Manager|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.0.0
	 * @return FluentAffiliate_Manager
	 */
	public static function get_instance() {

		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

	/**
	 * Register all hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function init() {

		// Gateway-agnostic: fires after every payment is saved, regardless of gateway.
		add_action('wu_payment_post_save', [$this, 'handle_payment_saved'], 10, 2);

		// Handle payment status transitions (e.g. pending → completed, completed → refunded).
		add_action('wu_transition_payment_status', [$this, 'handle_payment_status_change'], 10, 3);

		// Admin UI: affiliate assignment section on membership edit page.
		add_filter('wu_membership_options_sections', [$this, 'add_membership_affiliate_section'], 30, 2);

		// Save manual affiliate assignment from membership edit page.
		add_action('wu_save_membership', [$this, 'save_membership_affiliate'], 10, 1);
	}

	/**
	 * Handle a payment being saved.
	 *
	 * Called on `wu_payment_post_save`. Fires for every payment type:
	 * initial, renewal, upgrade, downgrade, etc.
	 *
	 * For initial payments: captures affiliate from FluentAffiliate tracking
	 * and stores it on the membership for future renewals.
	 *
	 * For renewal payments: reads the stored affiliate ID from the membership
	 * and fires a commission.
	 *
	 * @since 1.0.0
	 * @param array                     $data    The raw data array being saved.
	 * @param \WP_Ultimo\Models\Payment $payment The payment model instance.
	 * @return void
	 */
	public function handle_payment_saved($data, $payment) {

		if (! $payment) {
			return;
		}

		// Only process completed payments.
		if ('completed' !== $payment->get_status()) {
			return;
		}

		$membership = $payment->get_membership();

		if (! $membership) {
			return;
		}

		$transaction_type = $payment->get_transaction_type();

		if ('renewal' === $transaction_type) {
			$this->fire_renewal_commission($payment, $membership);
		} else {
			// Initial payment: capture affiliate from FluentAffiliate tracking.
			$this->capture_and_fire_initial_commission($payment, $membership);
		}
	}

	/**
	 * Handle payment status transitions.
	 *
	 * Covers two cases:
	 *   - pending → completed: fire commission if not already fired.
	 *   - completed → refunded: mark existing commission as refunded.
	 *
	 * @since 1.0.0
	 * @param string                    $new_status New payment status.
	 * @param string                    $old_status Previous payment status.
	 * @param \WP_Ultimo\Models\Payment $payment    The payment model instance.
	 * @return void
	 */
	public function handle_payment_status_change($new_status, $old_status, $payment) {

		if ('completed' === $new_status && 'pending' === $old_status) {
			// Payment just completed — wu_payment_post_save may not re-fire, so handle here.
			$membership = $payment->get_membership();
			if ($membership) {
				$transaction_type = $payment->get_transaction_type();
				if ('renewal' === $transaction_type) {
					$this->fire_renewal_commission($payment, $membership);
				} else {
					$this->capture_and_fire_initial_commission($payment, $membership);
				}
			}
		}

		if ('refunded' === $new_status && 'completed' === $old_status) {
			$this->handle_commission_refund($payment);
		}
	}

	/**
	 * Capture affiliate reference at initial signup and fire first commission.
	 *
	 * Checks (in order):
	 *   1. Manually assigned affiliate on the membership meta.
	 *   2. FluentAffiliate cookie (`fla_ref` or `fluent_affiliate_ref`).
	 *   3. FluentAffiliate session data.
	 *   4. User meta set by FluentAffiliate tracking.
	 *
	 * If an affiliate is found, stores it on the membership and fires a commission.
	 *
	 * @since 1.0.0
	 * @param \WP_Ultimo\Models\Payment    $payment    The payment model.
	 * @param \WP_Ultimo\Models\Membership $membership The membership model.
	 * @return void
	 */
	protected function capture_and_fire_initial_commission($payment, $membership) {

		$affiliate_id = $this->get_affiliate_id($membership, $payment);

		if (! $affiliate_id) {
			return;
		}

		// Persist affiliate ID on membership for future renewals.
		$membership->update_meta(self::META_AFFILIATE_ID, $affiliate_id);

		$this->create_commission($affiliate_id, $payment, $membership, 'payment');
	}

	/**
	 * Fire a commission for a renewal payment.
	 *
	 * Reads the affiliate ID stored on the membership at signup.
	 * No cookie/session lookup — the affiliate was already captured.
	 *
	 * @since 1.0.0
	 * @param \WP_Ultimo\Models\Payment    $payment    The payment model.
	 * @param \WP_Ultimo\Models\Membership $membership The membership model.
	 * @return void
	 */
	protected function fire_renewal_commission($payment, $membership) {

		$affiliate_id = (int) $membership->get_meta(self::META_AFFILIATE_ID);

		if (! $affiliate_id) {
			FluentAffiliate_Helper::log(
				'Renewal payment has no stored affiliate ID — skipping commission',
				[
					'payment_id'    => $payment->get_id(),
					'membership_id' => $membership->get_id(),
				]
			);
			return;
		}

		$this->create_commission($affiliate_id, $payment, $membership, 'renewal');
	}

	/**
	 * Resolve the affiliate ID for a payment from all available sources.
	 *
	 * Priority order:
	 *   1. Manually assigned affiliate in membership meta.
	 *   2. FluentAffiliate cookie (`fla_ref` or `fluent_affiliate_ref`).
	 *   3. FluentAffiliate session variable.
	 *   4. User meta set by FluentAffiliate tracking (`_fla_affiliate_id`).
	 *
	 * @since 1.0.0
	 * @param \WP_Ultimo\Models\Membership $membership The membership model.
	 * @param \WP_Ultimo\Models\Payment    $payment    The payment model (optional).
	 * @return int|false Affiliate ID or false if not found.
	 */
	protected function get_affiliate_id($membership, $payment = null) {

		// 1. Manually assigned via admin UI.
		$affiliate_id = (int) $membership->get_meta(self::META_AFFILIATE_ID);
		if ($affiliate_id > 0) {
			return $affiliate_id;
		}

		// 2. FluentAffiliate cookie — plugin uses 'fla_ref' as the primary cookie name.
		$cookie_names = ['fla_ref', 'fluent_affiliate_ref', 'fa_ref'];
		foreach ($cookie_names as $cookie_name) {
			if (! empty($_COOKIE[ $cookie_name ])) {
				$cookie_val = intval($_COOKIE[ $cookie_name ]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ($cookie_val > 0) {
					return $cookie_val;
				}
			}
		}

		// 3. Session variable (FluentAffiliate may store in session).
		if (session_id() && ! empty($_SESSION['fluent_affiliate_id'])) {
			$session_val = intval($_SESSION['fluent_affiliate_id']);
			if ($session_val > 0) {
				return $session_val;
			}
		}

		// 4. User meta set by FluentAffiliate tracking.
		$customer = $membership->get_customer();
		if ($customer && $customer->get_user_id()) {
			$user_id      = $customer->get_user_id();
			$meta_keys    = ['_fla_affiliate_id', 'fluent_affiliate_referrer', '_fluent_affiliate_id'];
			foreach ($meta_keys as $meta_key) {
				$user_meta_val = get_user_meta($user_id, $meta_key, true);
				if ($user_meta_val) {
					return intval($user_meta_val);
				}
			}
		}

		/**
		 * Filter to allow custom affiliate ID resolution.
		 *
		 * @since 1.0.0
		 * @param int|false                    $affiliate_id The resolved affiliate ID (false if not found).
		 * @param \WP_Ultimo\Models\Membership $membership   The membership model.
		 * @param \WP_Ultimo\Models\Payment    $payment      The payment model.
		 */
		return apply_filters('wu_fluentaffiliate_resolve_affiliate_id', false, $membership, $payment);
	}

	/**
	 * Create a commission record in FluentAffiliate.
	 *
	 * @since 1.0.0
	 * @param int                          $affiliate_id The affiliate ID.
	 * @param \WP_Ultimo\Models\Payment    $payment      The payment model.
	 * @param \WP_Ultimo\Models\Membership $membership   The membership model.
	 * @param string                       $type         Commission type: 'payment' or 'renewal'.
	 * @return bool True on success.
	 */
	protected function create_commission($affiliate_id, $payment, $membership, $type = 'payment') {

		$customer = $membership->get_customer();

		$commission_data = [
			'affiliate_id'   => $affiliate_id,
			'amount'         => $payment->get_total(),
			'currency'       => $payment->get_currency(),
			'reference_id'   => $payment->get_id(),
			'reference_type' => 'wu_payment',
			'description'    => sprintf(
				/* translators: 1: commission type (Payment/Renewal), 2: payment ID */
				__('Multisite Ultimate %1$s — Payment #%2$d', 'ultimate-multisite-fluentaffiliate'),
				'renewal' === $type ? __('Renewal', 'ultimate-multisite-fluentaffiliate') : __('Payment', 'ultimate-multisite-fluentaffiliate'),
				$payment->get_id()
			),
			'customer_email' => $customer ? $customer->get_email_address() : '',
			'customer_name'  => $customer ? $customer->get_display_name() : '',
			'membership_id'  => $membership->get_id(),
			'type'           => $type,
		];

		/**
		 * Filter commission data before creation.
		 *
		 * @since 1.0.0
		 * @param array                        $commission_data Commission data array.
		 * @param \WP_Ultimo\Models\Payment    $payment         The payment model.
		 * @param \WP_Ultimo\Models\Membership $membership      The membership model.
		 * @param int                          $affiliate_id    The affiliate ID.
		 */
		$commission_data = apply_filters(
			'wu_fluentaffiliate_commission_data',
			$commission_data,
			$payment,
			$membership,
			$affiliate_id
		);

		/**
		 * Fires before a commission is created.
		 *
		 * @since 1.0.0
		 * @param array $commission_data Commission data.
		 */
		do_action('wu_fluentaffiliate_before_create_commission', $commission_data);

		$commission_id = FluentAffiliate_Helper::create_commission($commission_data);

		return (bool) $commission_id;
	}

	/**
	 * Handle a commission refund when a payment is refunded.
	 *
	 * @since 1.0.0
	 * @param \WP_Ultimo\Models\Payment $payment The refunded payment.
	 * @return void
	 */
	protected function handle_commission_refund($payment) {

		$commission = FluentAffiliate_Helper::get_commission_by_reference($payment->get_id(), 'wu_payment');

		if (! $commission) {
			FluentAffiliate_Helper::log(
				'No commission found to refund',
				['payment_id' => $payment->get_id()]
			);
			return;
		}

		$commission_id = $commission['id'] ?? null;

		if (! $commission_id) {
			return;
		}

		/**
		 * Fires before a commission refund is processed.
		 *
		 * @since 1.0.0
		 * @param array                     $commission Commission data.
		 * @param \WP_Ultimo\Models\Payment $payment    The refunded payment.
		 */
		do_action('wu_fluentaffiliate_before_refund_commission', $commission, $payment);

		$success = FluentAffiliate_Helper::update_commission_status($commission_id, 'refunded');

		FluentAffiliate_Helper::log(
			$success ? 'Commission marked as refunded' : 'Failed to mark commission as refunded',
			[
				'commission_id' => $commission_id,
				'payment_id'    => $payment->get_id(),
			]
		);
	}

	/**
	 * Add the affiliate assignment section to the membership edit admin page.
	 *
	 * Injects a dropdown field under a new "Affiliate" tab so admins can
	 * manually assign or change the affiliate for any membership.
	 *
	 * @since 1.0.0
	 * @param array                        $sections   Existing tabbed sections.
	 * @param \WP_Ultimo\Models\Membership $membership The membership being edited.
	 * @return array Modified sections.
	 */
	public function add_membership_affiliate_section($sections, $membership) {

		$affiliate_id   = (int) $membership->get_meta(self::META_AFFILIATE_ID);
		$affiliates     = FluentAffiliate_Helper::get_active_affiliates();
		$affiliate_name = '';

		if ($affiliate_id) {
			$affiliate_data = FluentAffiliate_Helper::get_affiliate($affiliate_id);
			$affiliate_name = $affiliate_data ? $affiliate_data['name'] : sprintf(
				/* translators: %d: affiliate ID */
				__('Affiliate #%d', 'ultimate-multisite-fluentaffiliate'),
				$affiliate_id
			);
		}

		$fields = [
			'fluent_affiliate_id' => [
				'type'        => 'select',
				'title'       => __('Assigned Affiliate', 'ultimate-multisite-fluentaffiliate'),
				'desc'        => __('Select an affiliate to track commissions for this membership. All future renewal payments will generate a commission for the selected affiliate.', 'ultimate-multisite-fluentaffiliate'),
				'value'       => $affiliate_id ?: '',
				'placeholder' => __('Select Affiliate...', 'ultimate-multisite-fluentaffiliate'),
				'options'     => $affiliates,
			],
		];

		if ($affiliate_id && $affiliate_name) {
			$fields['fluent_affiliate_current'] = [
				'type'    => 'note',
				'desc'    => sprintf(
					/* translators: 1: affiliate name, 2: affiliate ID */
					__('Currently assigned to: <strong>%1$s</strong> (ID: %2$d)', 'ultimate-multisite-fluentaffiliate'),
					esc_html($affiliate_name),
					$affiliate_id
				),
				'classes' => 'sm:wu-p-2 wu-bg-green-100 wu-text-green-600 wu-rounded wu-w-full wu-border wu-border-solid wu-border-green-200',
			];
		}

		$sections['fluent_affiliate'] = [
			'title'  => __('FluentAffiliate', 'ultimate-multisite-fluentaffiliate'),
			'desc'   => __('Assign this membership to a FluentAffiliate affiliate. Commissions will be generated for each renewal payment.', 'ultimate-multisite-fluentaffiliate'),
			'icon'   => 'dashicons-wu-users',
			'fields' => $fields,
		];

		return $sections;
	}

	/**
	 * Save the manually assigned affiliate from the membership edit page.
	 *
	 * Hooked on `wu_save_membership`. Reads `fluent_affiliate_id` from POST,
	 * validates the affiliate, and updates or removes the membership meta.
	 *
	 * @since 1.0.0
	 * @param \WP_Ultimo\Admin_Pages\Membership_Edit_Admin_Page $admin_page The admin page object.
	 * @return void
	 */
	public function save_membership_affiliate($admin_page) {

		if (! isset($_POST['fluent_affiliate_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$membership = $admin_page->get_object();

		if (! $membership) {
			return;
		}

		$affiliate_id = intval($_POST['fluent_affiliate_id']); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ($affiliate_id > 0 && FluentAffiliate_Helper::validate_affiliate($affiliate_id)) {
			$membership->update_meta(self::META_AFFILIATE_ID, $affiliate_id);

			FluentAffiliate_Helper::log(
				'Affiliate manually assigned to membership',
				[
					'membership_id' => $membership->get_id(),
					'affiliate_id'  => $affiliate_id,
				]
			);
		} else {
			$membership->delete_meta(self::META_AFFILIATE_ID);

			FluentAffiliate_Helper::log(
				'Affiliate removed from membership',
				['membership_id' => $membership->get_id()]
			);
		}
	}
}
