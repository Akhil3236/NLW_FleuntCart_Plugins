<?php
/**
 * Weight-based shipping for FluentCart (NL tiers).
 *
 * @package FluentCartWeightShipping
 */

namespace FCWS;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\ShippingMethodsRender;
use FluentCart\Framework\Support\Arr;

/**
 * Core calculation and cart patching.
 */
class Shipping {

	private const META_GEWICHT = '_etch_fc_gewicht';

	private const OT_PRODUCT = 'product_variant_info';

	private const OT_VARIATION = 'etch_fc_variation';

	/**
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Register hooks (idempotent; safe to call from plugins_loaded or fluent_cart/init).
	 */
	public static function init(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_filter( 'fluent_cart/checkout/before_patch_checkout_data', array( __CLASS__, 'filter_before_patch' ), 25, 2 );
		add_action( 'fluent_cart/cart/cart_data_items_updated', array( __CLASS__, 'on_cart_items_updated' ), 25, 1 );
		add_filter( 'fluent_cart/cart/estimated_total', array( __CLASS__, 'filter_estimated_total' ), 999, 2 );
		add_action( 'fluent_cart/checkout/prepare_other_data', array( __CLASS__, 'on_prepare_order_after_draft' ), 15, 1 );
		add_filter( 'fluent_cart/checkout/after_patch_checkout_data_fragments', array( __CLASS__, 'filter_checkout_fragments_shipping_html' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer_checkout_for_shipping_html' ), 0 );
	}

	/**
	 * AJAX-fragmenten: vervang verzendopties-HTML door gewichtstarief (geen FluentCart-core wijziging).
	 *
	 * @param array<int, array<string, mixed>> $fragments FluentCart fragments.
	 * @param array<string, mixed>             $payload   o.a. cart.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_checkout_fragments_shipping_html( array $fragments, array $payload ): array {
		$cart = isset( $payload['cart'] ) ? $payload['cart'] : null;
		if ( ! $cart || ! self::cart_requires_shipping( $cart ) || ! self::should_patch_methods_display( $cart ) ) {
			return $fragments;
		}
		$html = self::render_shipping_methods_fragment_html( $cart );
		if ( '' === $html ) {
			return $fragments;
		}
		foreach ( $fragments as $i => $fr ) {
			if ( ! isset( $fr['selector'], $fr['type'] ) || 'replace' !== $fr['type'] ) {
				continue;
			}
			if ( '[data-fluent-cart-checkout-page-shipping-methods-wrapper]' !== $fr['selector'] ) {
				continue;
			}
			$fragments[ $i ]['content'] = $html;
		}
		return $fragments;
	}

	/**
	 * Eerste pageload: output buffer — vervang #shipping_methods door tier-HTML.
	 * De vervang-HTML wordt vóór ob_start() gebouwd: binnen een output-buffer-callback mag geen
	 * nieuwe ob_start() (zoals in ShippingMethodsRender::render), anders PHP fatal error.
	 */
	public static function maybe_buffer_checkout_for_shipping_html(): void {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! function_exists( 'is_page' ) || ! is_page() ) {
			return;
		}
		if ( ! class_exists( StoreSettings::class ) ) {
			return;
		}
		$page_id = (int) ( new StoreSettings() )->getCheckoutPageId();
		if ( $page_id <= 0 || (int) get_queried_object_id() !== $page_id ) {
			return;
		}
		$replacement = '';
		if ( class_exists( CartHelper::class ) ) {
			$cart = CartHelper::getCart();
			if ( $cart && self::cart_requires_shipping( $cart ) && self::should_patch_methods_display( $cart ) ) {
				$replacement = self::render_shipping_methods_fragment_html( $cart );
			}
		}
		ob_start(
			function ( string $buffer ) use ( $replacement ) {
				if ( '' === $replacement || strpos( $buffer, 'shipping_methods' ) === false ) {
					return $buffer;
				}
				$out = self::replace_shipping_methods_node_in_html( $buffer, $replacement );
				return is_string( $out ) ? $out : $buffer;
			}
		);
	}

	/**
	 * Vervang het blok met id="shipping_methods" door opnieuw gerenderde FluentCart-markup (tier + centen).
	 */
	private static function replace_shipping_methods_node_in_html( string $html, string $replacement ): ?string {
		if ( ! class_exists( \DOMDocument::class ) ) {
			return null;
		}
		libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$wrapped = '<?xml encoding="utf-8"?>' . $html;
		if ( ! @$doc->loadHTML( $wrapped ) ) {
			return null;
		}
		$old = $doc->getElementById( 'shipping_methods' );
		if ( ! $old || ! $old->parentNode ) {
			return null;
		}
		$tmp = new \DOMDocument();
		$frag_wrap = '<?xml encoding="utf-8"?><div id="fcws-ship-frag">' . $replacement . '</div>';
		if ( ! @$tmp->loadHTML( $frag_wrap ) ) {
			return null;
		}
		$wrap = $tmp->getElementById( 'fcws-ship-frag' );
		if ( ! $wrap ) {
			return null;
		}
		$new_node = null;
		foreach ( $wrap->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && 'div' === $child->nodeName && $child->getAttribute( 'id' ) === 'shipping_methods' ) {
				$new_node = $child;
				break;
			}
		}
		if ( ! $new_node ) {
			return null;
		}
		$imported = $doc->importNode( $new_node, true );
		$old->parentNode->replaceChild( $imported, $old );
		return $doc->saveHTML();
	}

	/**
	 * Zelfde opbouw als CheckoutRenderer::renderShippingOptions, daarna tier/charge uit cart.
	 */
	public static function render_shipping_methods_fragment_html( $cart ): string {
		if ( ! $cart || ! $cart->requireShipping() ) {
			return '';
		}
		$renderer = new CheckoutRenderer( $cart );
		$ref      = new \ReflectionObject( $renderer );
		$bill_p   = $ref->getProperty( 'billingAddress' );
		$ship_p   = $ref->getProperty( 'shippingAddress' );
		$bill_p->setAccessible( true );
		$ship_p->setAccessible( true );
		$billing_address  = $bill_p->getValue( $renderer );
		$shipping_address = $ship_p->getValue( $renderer );
		$country_code     = ( $shipping_address['country'] ?? '' ) ?: ( $billing_address['country'] ?? '' );
		$state_code       = ( $shipping_address['state'] ?? '' ) ?: ( $billing_address['state'] ?? '' );
		$billing_validations = array_filter( CheckoutFieldsSchema::getCheckoutFieldsRequirements( 'billing', 'physical' ) );
		if ( ! isset( $billing_validations['country'] ) ) {
			$country_code = ( new StoreSettings() )->get( 'store_country' );
		}
		$available = AddressHelper::getShippingMethods( $country_code, $state_code );
		$selected_id = Arr::get( $cart->checkout_data, 'shipping_data.shipping_method_id', '' );
		if ( ! $available || is_wp_error( $available ) ) {
			ob_start();
			( new ShippingMethodsRender( $available, $selected_id ) )->render();
			return (string) ob_get_clean();
		}
		foreach ( $available as $method ) {
			$method->charge_amount = CartHelper::calculateShippingMethodCharge( $method, $cart->cart_data );
		}
		$available = self::apply_weight_tier_to_methods( $available, $cart );
		ob_start();
		( new ShippingMethodsRender( $available, $selected_id ) )->render();
		return (string) ob_get_clean();
	}

	/**
	 * @param \FluentCart\App\Models\Cart $cart Winkelwagen.
	 */
	private static function should_patch_methods_display( $cart ): bool {
		$tier   = Arr::get( $cart->checkout_data, 'shipping_data.fct_weight_tier_label', '' );
		$charge = Arr::get( $cart->checkout_data, 'shipping_data.shipping_charge' );
		return '' !== $tier || ( null !== $charge && '' !== $charge );
	}

	/**
	 * @param array<int, object>|\WP_Error $methods Zone-methodes met charge_amount.
	 * @param \FluentCart\App\Models\Cart  $cart    Huidige winkelwagen.
	 * @return array<int, object>|\WP_Error
	 */
	private static function apply_weight_tier_to_methods( $methods, $cart ) {
		if ( is_wp_error( $methods ) || ! is_array( $methods ) || ! $cart || ! self::cart_requires_shipping( $cart ) ) {
			return $methods;
		}

		$tier_label = Arr::get( $cart->checkout_data, 'shipping_data.fct_weight_tier_label', '' );
		$charge     = Arr::get( $cart->checkout_data, 'shipping_data.shipping_charge' );
		if ( '' === $tier_label && ( null === $charge || '' === $charge ) ) {
			return $methods;
		}

		$selected_id = (int) Arr::get( $cart->checkout_data, 'shipping_data.shipping_method_id', 0 );
		$count       = count( $methods );

		foreach ( $methods as $method ) {
			$mid = isset( $method->id ) ? (int) $method->id : 0;
			$use = ( $selected_id > 0 && $mid === $selected_id ) || ( $selected_id <= 0 && 1 === $count );
			if ( ! $use ) {
				continue;
			}
			if ( null !== $charge && '' !== $charge ) {
				$method->charge_amount = (int) $charge;
			}
			if ( '' !== $tier_label ) {
				$method->title = $tier_label;
			}
		}

		return $methods;
	}

	/**
	 * FluentCart zet bij orderaanmaak shipping_total via zone-berekening; corrigeer naar winkelwagen (gewichtstarief).
	 *
	 * @param array<string, mixed> $context Keys: cart, order, prev_order, request_data, validated_data.
	 */
	public static function on_prepare_order_after_draft( array $context ): void {
		$cart  = isset( $context['cart'] ) ? $context['cart'] : null;
		$order = isset( $context['order'] ) ? $context['order'] : null;
		if ( ! $cart || ! $order || ! self::cart_requires_shipping( $cart ) ) {
			return;
		}
		$tier_label = Arr::get( $cart->checkout_data, 'shipping_data.fct_weight_tier_label', '' );
		if ( '' === $tier_label ) {
			return;
		}
		$correct = (int) Arr::get( $cart->checkout_data, 'shipping_data.shipping_charge', 0 );
		if ( $correct <= 0 ) {
			return;
		}
		$current = (int) $order->shipping_total;
		if ( $correct === $current ) {
			return;
		}
		$diff = $correct - $current;
		$order->shipping_total = $correct;
		$order->total_amount   = (int) $order->total_amount + $diff;
		$order->save();

		// Mollie (e.a.) gebruiken OrderTransaction::total bij create payment; die wordt vóór deze hook gezet.
		self::sync_pending_charge_transaction_total( $order );
	}

	/**
	 * Zet pending charge-transactie gelijk aan order-totaal zodat gateways het juiste bedrag krijgen.
	 *
	 * @param \FluentCart\App\Models\Order $order Order.
	 */
	private static function sync_pending_charge_transaction_total( $order ): void {
		if ( ! $order || ! isset( $order->id ) ) {
			return;
		}
		$tx = OrderTransaction::query()
			->where( 'order_id', (int) $order->id )
			->where( 'transaction_type', Status::TRANSACTION_TYPE_CHARGE )
			->orderBy( 'id', 'desc' )
			->first();
		if ( ! $tx ) {
			return;
		}
		$want = (int) $order->total_amount;
		if ( (int) $tx->total === $want ) {
			return;
		}
		$tx->total = $want;
		$tx->save();
	}

	/**
	 * @param array $fillData Checkout + cart payload.
	 * @param array $data     Context (see FluentCart WebCheckoutHandler).
	 * @return array
	 */
	public static function filter_before_patch( array $fill_data, array $data ): array {
		$cart = isset( $data['cart'] ) ? $data['cart'] : null;
		if ( ! $cart ) {
			return $fill_data;
		}
		$req_ship = self::cart_requires_shipping( $cart );
		if ( ! $req_ship ) {
			return $fill_data;
		}

		$cart_data = Arr::get( $fill_data, 'cart_data', array() );

		$tier = self::compute_tier_for_cart_data( $cart_data );

		$method_id = self::resolve_shipping_method_id( $cart, $fill_data );
		$prev_charge = (int) Arr::get( $fill_data, 'checkout_data.shipping_data.shipping_charge', 0 );
		$prev_method = Arr::get( $fill_data, 'checkout_data.shipping_data.shipping_method_id' );

		self::apply_tier_to_fill_data( $fill_data, $tier, $method_id );

		// Flag when incoming checkout differed from computed tier (not post-merge equality).
		if ( $prev_charge !== $tier['cents'] || (int) $prev_method !== (int) $method_id ) {
			$fill_data['hook_changes']['shipping'] = true;
		}

		return $fill_data;
	}

	/**
	 * @param array $payload FluentCart event payload.
	 */
	public static function on_cart_items_updated( array $payload ): void {
		$cart = Arr::get( $payload, 'cart' );
		if ( ! $cart ) {
			return;
		}
		if ( ! self::cart_requires_shipping( $cart ) ) {
			return;
		}

		$cart_data = $cart->cart_data ?? array();

		$tier = self::compute_tier_for_cart_data( $cart_data );

		$method_id = self::resolve_shipping_method_id( $cart, null );
		$checkout = is_array( $cart->checkout_data ) ? $cart->checkout_data : array();
		$prev     = (int) Arr::get( $checkout, 'shipping_data.shipping_charge', 0 );

		$checkout['shipping_data'] = array_merge(
			Arr::get( $checkout, 'shipping_data', array() ),
			array(
				'shipping_charge'          => $tier['cents'],
				'shipping_method_id'       => $method_id,
				'fct_weight_tier_label'    => $tier['label'],
				'fct_weight_total_grams'   => $tier['grams'],
			)
		);

		$cart->checkout_data = $checkout;
		$cart->save();

		if ( $prev !== $tier['cents'] ) {
			do_action(
				'fluent_cart/checkout/shipping_data_changed',
				array( 'cart' => $cart )
			);
		}
	}

	/**
	 * Align totals when FluentCart recalculates zone shipping (e.g. checkout summary AJAX).
	 *
	 * @param int   $total Cents.
	 * @param array $data  Contains 'cart' => Cart.
	 * @return int
	 */
	public static function filter_estimated_total( $total, $data ) {
		$cart = isset( $data['cart'] ) ? $data['cart'] : null;
		if ( ! $cart ) {
			return $total;
		}
		if ( ! self::cart_requires_shipping( $cart ) ) {
			return $total;
		}

		$cart_data = $cart->cart_data ?? array();

		$tier = self::compute_tier_for_cart_data( $cart_data );

		$method_id = self::resolve_shipping_method_id( $cart, null );
		$current   = (int) Arr::get( $cart->checkout_data, 'shipping_data.shipping_charge', 0 );
		$target    = $tier['cents'];

		if ( $current === $target
			&& (int) Arr::get( $cart->checkout_data, 'shipping_data.shipping_method_id', 0 ) === (int) $method_id
			&& Arr::get( $cart->checkout_data, 'shipping_data.fct_weight_tier_label' ) === $tier['label']
		) {
			return $total;
		}

		$checkout = is_array( $cart->checkout_data ) ? $cart->checkout_data : array();
		$checkout['shipping_data'] = array_merge(
			Arr::get( $checkout, 'shipping_data', array() ),
			array(
				'shipping_charge'        => $target,
				'shipping_method_id'     => $method_id,
				'fct_weight_tier_label'  => $tier['label'],
				'fct_weight_total_grams' => $tier['grams'],
			)
		);
		$cart->checkout_data = $checkout;
		$cart->save();

		return $total - $current + $target;
	}

	/**
	 * @param \FluentCart\App\Models\Cart $cart Cart model.
	 */
	private static function cart_requires_shipping( $cart ): bool {
		if ( ! method_exists( $cart, 'requireShipping' ) ) {
			return false;
		}
		return (bool) $cart->requireShipping();
	}

	/**
	 * @param array $cart_data FluentCart cart_data rows.
	 * @return array{cents: int, label: string, grams: float}
	 */
	private static function compute_tier_for_cart_data( array $cart_data ): array {
		$total_grams = 0.0;
		foreach ( $cart_data as $item ) {
			if ( 'physical' !== Arr::get( $item, 'fulfillment_type' ) ) {
				continue;
			}
			$qty = (int) Arr::get( $item, 'quantity', 1 );
			$w   = self::line_weight_grams( $item );
			$total_grams += $w * $qty;
		}
		return self::tier_from_total_grams( $total_grams );
	}

	/**
	 * @param array $item Cart line.
	 */
	private static function line_weight_grams( array $item ): float {
		$post_id = (int) Arr::get( $item, 'post_id', 0 );
		$var_id  = (int) Arr::get( $item, 'object_id', 0 );

		$raw = '';
		if ( class_exists( '\EtchFluentCart\Services\Product_Service' ) ) {
			$custom = \EtchFluentCart\Services\Product_Service::get_custom_fields_for_variation( $var_id, $post_id );
			$raw    = isset( $custom['gewicht'] ) ? (string) $custom['gewicht'] : '';
		}
		if ( '' === $raw ) {
			$raw = self::meta_gewicht_fallback( $var_id, $post_id );
		}
		return self::parse_grams( $raw );
	}

	/**
	 * Direct ProductMeta read when Etch bridge is unavailable.
	 */
	private static function meta_gewicht_fallback( int $variation_id, int $post_id ): string {
		if ( $variation_id > 0 ) {
			$vm = ProductMeta::query()
				->where( 'object_id', $variation_id )
				->where( 'object_type', self::OT_VARIATION )
				->where( 'meta_key', self::META_GEWICHT )
				->first();
			if ( $vm && null !== $vm->meta_value && (string) $vm->meta_value !== '' ) {
				return is_string( $vm->meta_value ) ? $vm->meta_value : (string) $vm->meta_value;
			}
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		$pm = ProductMeta::query()
			->where( 'object_id', $post_id )
			->where( 'object_type', self::OT_PRODUCT )
			->where( 'meta_key', self::META_GEWICHT )
			->first();
		if ( ! $pm || null === $pm->meta_value ) {
			return '';
		}
		return is_string( $pm->meta_value ) ? $pm->meta_value : (string) $pm->meta_value;
	}

	/**
	 * Parse "240 g", "1,2 kg", "500" to grams.
	 */
	public static function parse_grams( string $raw ): float {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 0.0;
		}
		$lower = strtolower( $raw );
		if ( preg_match( '/([\d.,]+)\s*kg\b/u', $lower, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] ) * 1000.0;
		}
		if ( preg_match( '/([\d.,]+)/u', $raw, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] );
		}
		return 0.0;
	}

	/**
	 * @param float $total_grams Sum of line weights × qty.
	 * @return array{cents: int, label: string, grams: float}
	 */
	private static function tier_from_total_grams( float $total_grams ): array {
		$g = max( 0.0, $total_grams );

		if ( $g <= 49 ) {
			return array(
				'cents'  => 0,
				'label'  => __( 'Brievenbus', 'fluent-cart-weight-shipping' ),
				'grams'  => $g,
			);
		}
		if ( $g <= 99 ) {
			return array(
				'cents'  => 288,
				'label'  => __( 'Brievenbuspakket', 'fluent-cart-weight-shipping' ),
				'grams'  => $g,
			);
		}
		if ( $g <= 349 ) {
			return array(
				'cents'  => 384,
				'label'  => __( 'Brievenbuspakket', 'fluent-cart-weight-shipping' ),
				'grams'  => $g,
			);
		}
		if ( $g <= 1499 ) {
			return array(
				'cents'  => 480,
				'label'  => __( 'Brievenbuspakket', 'fluent-cart-weight-shipping' ),
				'grams'  => $g,
			);
		}
		return array(
			'cents'  => 725,
			'label'  => __( 'Pakketpost', 'fluent-cart-weight-shipping' ),
			'grams'  => $g,
		);
	}

	/**
	 * @param \FluentCart\App\Models\Cart $cart      Cart.
	 * @param array|null                  $fill_data Optional fill payload.
	 * @return int|null
	 */
	private static function resolve_shipping_method_id( $cart, ?array $fill_data ) {
		$checkout = $fill_data ? Arr::get( $fill_data, 'checkout_data', array() ) : $cart->checkout_data;
		$country  = Arr::get( $checkout, 'form_data.shipping_country' );
		$state    = Arr::get( $checkout, 'form_data.shipping_state' );
		if ( ! $country ) {
			$country = Arr::get( $checkout, 'form_data.billing_country' );
			$state   = Arr::get( $checkout, 'form_data.billing_state' );
		}

		$methods = AddressHelper::getShippingMethods( $country, $state );
		if ( is_wp_error( $methods ) || empty( $methods ) ) {
			$prev = Arr::get( $checkout, 'shipping_data.shipping_method_id' );
			return $prev ? (int) $prev : null;
		}

		$current = Arr::get( $checkout, 'shipping_data.shipping_method_id' );
		if ( $current ) {
			foreach ( $methods as $m ) {
				if ( (int) $m->id === (int) $current ) {
					return (int) $current;
				}
			}
		}

		$first = $methods[0];
		return isset( $first->id ) ? (int) $first->id : null;
	}

	/**
	 * @param array    $fill_data Reference.
	 * @param array    $tier      Tier row.
	 * @param int|null $method_id Shipping method id.
	 */
	private static function apply_tier_to_fill_data( array &$fill_data, array $tier, $method_id ): void {
		if ( ! isset( $fill_data['checkout_data'] ) || ! is_array( $fill_data['checkout_data'] ) ) {
			$fill_data['checkout_data'] = array();
		}
		$fill_data['checkout_data']['shipping_data'] = array_merge(
			Arr::get( $fill_data['checkout_data'], 'shipping_data', array() ),
			array(
				'shipping_charge'          => $tier['cents'],
				'shipping_method_id'       => $method_id,
				'fct_weight_tier_label'    => $tier['label'],
				'fct_weight_total_grams'   => $tier['grams'],
			)
		);
	}

}
