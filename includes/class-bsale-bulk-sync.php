<?php
/**
 * Bsale_Bulk_Sync
 * Sincronización masiva de stock y precios desde Bsale hacia WooCommerce.
 * Usa lotes AJAX secuenciales para manejar catálogos grandes sin agotar el tiempo de ejecución.
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Bulk_Sync {

	public function __construct() {
		add_action( 'wp_ajax_bsale_get_sync_products', [ $this, 'ajax_get_products' ] );
		add_action( 'wp_ajax_bsale_sync_stock_batch',  [ $this, 'ajax_sync_stock_batch' ] );
		add_action( 'wp_ajax_bsale_sync_price_batch',  [ $this, 'ajax_sync_price_batch' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: lista completa de productos a procesar
	// -------------------------------------------------------------------------

	public function ajax_get_products(): void {
		check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
		}

		$items = $this->collect_products();
		wp_send_json_success( [ 'items' => $items, 'total' => count( $items ) ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: lote de stock
	// -------------------------------------------------------------------------

	public function ajax_sync_stock_batch(): void {
		check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 90 );

		$settings  = get_option( BSALE_SYNC_OPTION, [] );
		$office_id = (int) ( $settings['office_id'] ?? 0 );
		$raw_items = $_POST['items'] ?? []; // phpcs:ignore
		$items     = is_array( $raw_items ) ? $raw_items : [];
		$api       = new Bsale_API();
		$results   = [];

		foreach ( $items as $item ) {
			$sku        = sanitize_text_field( $item['sku'] ?? '' );
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$name       = sanitize_text_field( $item['name'] ?? '' );

			if ( ! $sku || ! $product_id ) continue;

			$results[] = $this->sync_stock_item( $api, $sku, $product_id, $name, $office_id );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: lote de precios
	// -------------------------------------------------------------------------

	public function ajax_sync_price_batch(): void {
		check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 90 );

		$settings      = get_option( BSALE_SYNC_OPTION, [] );
		$price_list_id = (int) ( $settings['price_list_id'] ?? 0 );
		$raw_items     = $_POST['items'] ?? []; // phpcs:ignore
		$items         = is_array( $raw_items ) ? $raw_items : [];
		$api           = new Bsale_API();
		$results       = [];

		if ( ! $price_list_id ) {
			wp_send_json_error( [ 'message' => 'No hay lista de precio configurada en la pestaña Documentos.' ] );
		}

		foreach ( $items as $item ) {
			$sku        = sanitize_text_field( $item['sku'] ?? '' );
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$name       = sanitize_text_field( $item['name'] ?? '' );

			if ( ! $sku || ! $product_id ) continue;

			$results[] = $this->sync_price_item( $api, $sku, $product_id, $name, $price_list_id );
		}

		wp_send_json_success( [ 'results' => $results ] );
	}

	// -------------------------------------------------------------------------
	// Sync stock de un SKU
	// -------------------------------------------------------------------------

	private function sync_stock_item( Bsale_API $api, string $sku, int $product_id, string $name, int $office_id ): array {
		$base = [ 'sku' => $sku, 'product_id' => $product_id, 'name' => $name ];

		$params = [ 'code' => $sku ];
		if ( $office_id ) {
			$params['officeid'] = $office_id;
		}

		$data = $api->get( 'stocks.json', $params );

		if ( is_wp_error( $data ) ) {
			return $base + [ 'status' => 'error', 'detail' => $data->get_error_message() ];
		}

		if ( empty( $data['items'] ) ) {
			return $base + [ 'status' => 'not_found', 'detail' => '' ];
		}

		$qty = 0;
		foreach ( $data['items'] as $stock_item ) {
			$qty += (float) ( $stock_item['quantityAvailable'] ?? 0 );
		}
		$qty = (int) round( $qty );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $base + [ 'status' => 'error', 'detail' => 'Producto WC no encontrado' ];
		}

		$prev = (int) $product->get_stock_quantity();

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $qty );
		$product->save();
		wc_delete_product_transients( $product_id );

		return $base + [
			'status' => 'ok',
			'prev'   => $prev,
			'new'    => $qty,
			'detail' => $prev . ' → ' . $qty . ' uds.',
		];
	}

	// -------------------------------------------------------------------------
	// Sync precio de un SKU
	// -------------------------------------------------------------------------

	private function sync_price_item( Bsale_API $api, string $sku, int $product_id, string $name, int $price_list_id ): array {
		$base = [ 'sku' => $sku, 'product_id' => $product_id, 'name' => $name ];

		$variant = $api->get_variant_by_sku( $sku );

		if ( is_wp_error( $variant ) ) {
			return $base + [ 'status' => 'error', 'detail' => $variant->get_error_message() ];
		}

		if ( ! $variant ) {
			return $base + [ 'status' => 'not_found', 'detail' => '' ];
		}

		$variant_id = (int) $variant['id'];
		$detail     = $api->get_price_list_detail( $price_list_id, $variant_id );

		if ( is_wp_error( $detail ) ) {
			return $base + [ 'status' => 'error', 'detail' => $detail->get_error_message() ];
		}

		if ( ! $detail ) {
			return $base + [ 'status' => 'not_in_list', 'detail' => 'No está en la lista de precios' ];
		}

		// variantValueWithTaxes = precio con IVA incluido (igual a como WC guarda los precios en tiendas chilenas)
		$price = (float) ( $detail['variantValueWithTaxes'] ?? 0 );

		if ( $price <= 0 ) {
			return $base + [ 'status' => 'error', 'detail' => 'Precio inválido: $' . number_format( $price, 0, ',', '.' ) ];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $base + [ 'status' => 'error', 'detail' => 'Producto WC no encontrado' ];
		}

		$prev = (float) $product->get_regular_price();
		$product->set_regular_price( (string) $price );
		$product->save();
		wc_delete_product_transients( $product_id );

		return $base + [
			'status' => 'ok',
			'prev'   => $prev,
			'new'    => $price,
			'detail' => '$' . number_format( $prev, 0, ',', '.' ) . ' → $' . number_format( $price, 0, ',', '.' ),
		];
	}

	// -------------------------------------------------------------------------
	// Recolecta todos los productos WC publicados con SKU
	// Variables: solo variaciones; simples/externos: directamente
	// -------------------------------------------------------------------------

	private function collect_products(): array {
		$items = [];
		$ids   = wc_get_products( [
			'limit'  => -1,
			'status' => 'publish',
			'return' => 'ids',
		] );

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) continue;

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $var_id ) {
					$variation = wc_get_product( $var_id );
					if ( ! $variation ) continue;
					$sku = $variation->get_sku();
					if ( $sku ) {
						$items[] = [
							'sku'        => $sku,
							'product_id' => $var_id,
							'name'       => $variation->get_name(),
						];
					}
				}
			} else {
				$sku = $product->get_sku();
				if ( $sku ) {
					$items[] = [
						'sku'        => $sku,
						'product_id' => $product_id,
						'name'       => $product->get_name(),
					];
				}
			}
		}

		return $items;
	}
}
