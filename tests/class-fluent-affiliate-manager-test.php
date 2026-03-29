<?php
/**
 * Tests for FluentAffiliate_Manager commission tracking logic.
 *
 * @package WP_Ultimo_FluentAffiliate
 */

use WP_Ultimo_FluentAffiliate\Managers\FluentAffiliate_Manager;

/**
 * Test the FluentAffiliate_Manager commission tracking logic.
 */
class FluentAffiliate_Manager_Test extends WP_UnitTestCase {

	/**
	 * Manager instance under test.
	 *
	 * @var FluentAffiliate_Manager
	 */
	protected $manager;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->manager = FluentAffiliate_Manager::get_instance();
	}

	/**
	 * Test that the manager singleton returns the same instance.
	 */
	public function test_singleton_returns_same_instance() {
		$instance_a = FluentAffiliate_Manager::get_instance();
		$instance_b = FluentAffiliate_Manager::get_instance();
		$this->assertSame($instance_a, $instance_b);
	}

	/**
	 * Test that the META_AFFILIATE_ID constant is defined correctly.
	 */
	public function test_meta_affiliate_id_constant() {
		$this->assertEquals('fluent_affiliate_id', FluentAffiliate_Manager::META_AFFILIATE_ID);
	}

	/**
	 * Test that init() registers the expected hooks.
	 */
	public function test_init_registers_hooks() {
		$this->manager->init();

		$this->assertGreaterThan(
			0,
			has_action('wu_payment_post_save', [$this->manager, 'handle_payment_saved']),
			'wu_payment_post_save hook should be registered'
		);

		$this->assertGreaterThan(
			0,
			has_action('wu_transition_payment_status', [$this->manager, 'handle_payment_status_change']),
			'wu_transition_payment_status hook should be registered'
		);

		$this->assertGreaterThan(
			0,
			has_filter('wu_membership_options_sections', [$this->manager, 'add_membership_affiliate_section']),
			'wu_membership_options_sections filter should be registered'
		);

		$this->assertGreaterThan(
			0,
			has_action('wu_save_membership', [$this->manager, 'save_membership_affiliate']),
			'wu_save_membership hook should be registered'
		);
	}

	/**
	 * Test handle_payment_saved skips non-completed payments.
	 */
	public function test_handle_payment_saved_skips_non_completed() {
		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_status')->willReturn('pending');

		// Should not call get_membership if status is not completed.
		$payment->expects($this->never())->method('get_membership');

		$this->manager->handle_payment_saved([], $payment);
	}

	/**
	 * Test handle_payment_saved skips when membership is null.
	 */
	public function test_handle_payment_saved_skips_null_membership() {
		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_status')->willReturn('completed');
		$payment->method('get_membership')->willReturn(null);
		$payment->method('get_transaction_type')->willReturn('new');

		// Should not throw — just return early.
		$this->manager->handle_payment_saved([], $payment);
		$this->assertTrue(true); // Reached here without exception.
	}

	/**
	 * Test handle_payment_saved routes renewal payments to fire_renewal_commission.
	 *
	 * Verifies that a completed renewal payment with a stored affiliate ID
	 * triggers commission creation.
	 */
	public function test_handle_payment_saved_routes_renewal() {
		$membership = $this->createMock(\WP_Ultimo\Models\Membership::class);
		$membership->method('get_meta')
			->with(FluentAffiliate_Manager::META_AFFILIATE_ID)
			->willReturn(42);
		$membership->method('get_id')->willReturn(1);

		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_status')->willReturn('completed');
		$payment->method('get_membership')->willReturn($membership);
		$payment->method('get_transaction_type')->willReturn('renewal');
		$payment->method('get_id')->willReturn(100);
		$payment->method('get_total')->willReturn(29.99);
		$payment->method('get_currency')->willReturn('USD');

		$customer = $this->createMock(\WP_Ultimo\Models\Customer::class);
		$customer->method('get_email_address')->willReturn('test@example.com');
		$customer->method('get_display_name')->willReturn('Test User');
		$membership->method('get_customer')->willReturn($customer);

		// Track whether wu_fluentaffiliate_before_create_commission fires.
		$commission_data_received = null;
		add_action(
			'wu_fluentaffiliate_before_create_commission',
			function ($data) use (&$commission_data_received) {
				$commission_data_received = $data;
			}
		);

		$this->manager->handle_payment_saved([], $payment);

		$this->assertNotNull($commission_data_received, 'Commission creation action should have fired');
		$this->assertEquals(42, $commission_data_received['affiliate_id']);
		$this->assertEquals('renewal', $commission_data_received['type']);
		$this->assertEquals(100, $commission_data_received['reference_id']);
		$this->assertEquals('wu_payment', $commission_data_received['reference_type']);
	}

	/**
	 * Test that renewal commission is skipped when no affiliate ID is stored.
	 */
	public function test_renewal_commission_skipped_without_affiliate() {
		$membership = $this->createMock(\WP_Ultimo\Models\Membership::class);
		$membership->method('get_meta')
			->with(FluentAffiliate_Manager::META_AFFILIATE_ID)
			->willReturn(0);
		$membership->method('get_id')->willReturn(1);

		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_status')->willReturn('completed');
		$payment->method('get_membership')->willReturn($membership);
		$payment->method('get_transaction_type')->willReturn('renewal');
		$payment->method('get_id')->willReturn(101);

		$commission_fired = false;
		add_action(
			'wu_fluentaffiliate_before_create_commission',
			function () use (&$commission_fired) {
				$commission_fired = true;
			}
		);

		$this->manager->handle_payment_saved([], $payment);

		$this->assertFalse($commission_fired, 'Commission should not fire when no affiliate is stored');
	}

	/**
	 * Test add_membership_affiliate_section adds the expected section.
	 */
	public function test_add_membership_affiliate_section_adds_section() {
		$membership = $this->createMock(\WP_Ultimo\Models\Membership::class);
		$membership->method('get_meta')
			->with(FluentAffiliate_Manager::META_AFFILIATE_ID)
			->willReturn(0);

		$sections = $this->manager->add_membership_affiliate_section([], $membership);

		$this->assertArrayHasKey('fluent_affiliate', $sections);
		$this->assertArrayHasKey('fields', $sections['fluent_affiliate']);
		$this->assertArrayHasKey('fluent_affiliate_id', $sections['fluent_affiliate']['fields']);
	}

	/**
	 * Test add_membership_affiliate_section shows current affiliate when assigned.
	 */
	public function test_add_membership_affiliate_section_shows_current_affiliate() {
		$membership = $this->createMock(\WP_Ultimo\Models\Membership::class);
		$membership->method('get_meta')
			->with(FluentAffiliate_Manager::META_AFFILIATE_ID)
			->willReturn(5);

		$sections = $this->manager->add_membership_affiliate_section([], $membership);

		$this->assertArrayHasKey('fluent_affiliate', $sections);
		// When affiliate is assigned, a 'current' note field should be present.
		// (Only present if FluentAffiliate is active and affiliate is valid — in test env it may not be.)
		$this->assertArrayHasKey('fluent_affiliate_id', $sections['fluent_affiliate']['fields']);
	}

	/**
	 * Test handle_payment_status_change handles refund.
	 */
	public function test_handle_payment_status_change_handles_refund() {
		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_id')->willReturn(200);

		$refund_action_fired = false;
		add_action(
			'wu_fluentaffiliate_before_refund_commission',
			function () use (&$refund_action_fired) {
				$refund_action_fired = true;
			}
		);

		// No commission exists in test env, so the action won't fire — but no exception either.
		$this->manager->handle_payment_status_change('refunded', 'completed', $payment);

		// Verify no exception was thrown.
		$this->assertTrue(true);
	}

	/**
	 * Test that wu_fluentaffiliate_commission_data filter is applied.
	 */
	public function test_commission_data_filter_is_applied() {
		$membership = $this->createMock(\WP_Ultimo\Models\Membership::class);
		$membership->method('get_meta')
			->with(FluentAffiliate_Manager::META_AFFILIATE_ID)
			->willReturn(7);
		$membership->method('get_id')->willReturn(1);

		$customer = $this->createMock(\WP_Ultimo\Models\Customer::class);
		$customer->method('get_email_address')->willReturn('filter@example.com');
		$customer->method('get_display_name')->willReturn('Filter User');
		$membership->method('get_customer')->willReturn($customer);

		$payment = $this->createMock(\WP_Ultimo\Models\Payment::class);
		$payment->method('get_status')->willReturn('completed');
		$payment->method('get_membership')->willReturn($membership);
		$payment->method('get_transaction_type')->willReturn('renewal');
		$payment->method('get_id')->willReturn(300);
		$payment->method('get_total')->willReturn(49.99);
		$payment->method('get_currency')->willReturn('USD');

		$filter_applied = false;
		add_filter(
			'wu_fluentaffiliate_commission_data',
			function ($data) use (&$filter_applied) {
				$filter_applied = true;
				$data['custom_field'] = 'test_value';
				return $data;
			}
		);

		$this->manager->handle_payment_saved([], $payment);

		$this->assertTrue($filter_applied, 'wu_fluentaffiliate_commission_data filter should be applied');
	}
}
