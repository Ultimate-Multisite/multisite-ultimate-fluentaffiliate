=== Ultimate Multisite: FluentAffiliate Integration ===
Contributors: superdav42
Tags: affiliate, fluentaffiliate, multisite, woocommerce, recurring
Requires at least: 5.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track recurring commissions in FluentAffiliate for every Multisite Ultimate membership renewal — gateway-agnostic.

== Description ==

This addon bridges **Multisite Ultimate** (formerly WP Ultimo) and **FluentAffiliate** to enable automatic recurring commission tracking for subscription renewals.

**The problem it solves:**

FluentAffiliate tracks the *initial* payment at the form submission layer. But recurring subscription renewals happen at the gateway webhook layer (Stripe, PayPal, etc.), which is invisible to FluentAffiliate. This addon closes that gap.

**Features:**

* **Recurring commission tracking** — Stores the affiliate ID on the membership at signup, then fires a commission on every renewal payment.
* **Gateway-agnostic** — Hooks at the payment model layer (`wu_payment_post_save`), not at the Stripe gateway layer. Works with Stripe, PayPal, Manual, and all other gateways.
* **Manual affiliate assignment** — Admins can manually assign or change the affiliate for any membership via the membership edit page.
* **Refund handling** — Marks commissions as refunded when a payment is refunded.
* **Extensible** — Provides filters and actions for custom commission logic.

**FluentAffiliate API used:**

* `FluentAffiliate\App\Models\Affiliate` — affiliate model
* `FluentAffiliate\App\Models\Commission` — commission model
* `FluentAffiliate\App\Services\CommissionService` — service class
* `fluentAffiliate()` — main plugin helper function

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ultimate-multisite-fluentaffiliate/`.
2. Network Activate the plugin through the 'Plugins' menu in WordPress Network Admin.
3. Ensure both **Multisite Ultimate** and **FluentAffiliate** are active.

== Frequently Asked Questions ==

= Does this work with PayPal? =

Yes. The addon hooks at the payment model layer, not the Stripe gateway layer. It works with any gateway supported by Multisite Ultimate.

= How is the affiliate captured at signup? =

The addon checks (in order): manually assigned affiliate on the membership, FluentAffiliate cookie (`fla_ref`), session variable, and user meta set by FluentAffiliate tracking.

= Can I assign an affiliate retroactively? =

Yes. Go to Network Admin → Memberships → Edit Membership → FluentAffiliate tab.

== Changelog ==

= 1.0.0 =
* Initial release.
* Recurring commission tracking via `wu_payment_post_save` hook.
* Manual affiliate assignment on membership edit page.
* Refund handling via `wu_transition_payment_status` hook.
* Unit tests for commission tracking logic.
