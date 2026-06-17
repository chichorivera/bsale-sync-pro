<?php
/**
 * Bsale_Stock_Sync
 * Endpoint REST que recibe webhooks de Bsale y sincroniza stock y precios en WooCommerce.
 *
 * URL: POST {site}/wp-json/bsale/v1/stock?secret={webhook_secret}
 *
 * Topics manejados:
 *   - stock : resourceId = variantId, officeId = sucursal donde cambió el stock
 *   - price : resourceId = variantId, priceListId = lista de precios modificada
 *
 * Flujo (ambos topics):
 *   1. Validar secret → responder 200 inmediatamente
 *   2. Programar wp_schedule_single_event (procesamiento async)
 *   3. Cron: consulta Bsale → obtiene SKU → actualiza producto WC
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Stock_Sync {

    public function __construct() {
        add_action( 'rest_api_init',               [ $this, 'register_routes' ] );
        add_action( 'bsale_process_stock_webhook', [ $this, 'process_stock_webhook' ], 10, 2 );
        add_action( 'bsale_process_price_webhook', [ $this, 'process_price_webhook' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Endpoint REST
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'bsale/v1', '/stock', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );
    }

    public function verify_secret( WP_REST_Request $request ): bool {
        $settings = get_option( BSALE_SYNC_OPTION, [] );
        $secret   = $settings['webhook_secret'] ?? '';

        if ( empty( $secret ) ) return false;

        return hash_equals( $secret, (string) $request->get_param( 'secret' ) );
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $body  = $request->get_json_params() ?? [];
        $topic = $body['topic'] ?? '';

        if ( empty( $body['resourceId'] ) || ! in_array( $topic, [ 'stock', 'price' ], true ) ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $resource_id = (int) $body['resourceId']; // variantId en Bsale

        update_option( 'bsale_last_webhook', time(), false );

        switch ( $topic ) {
            case 'stock':
                $office_id = (int) ( $body['officeId'] ?? 0 );
                $this->add_log( sprintf(
                    'WEBHOOK_RECEIVED  topic=stock  variantId=%d  officeId=%d  action=%s',
                    $resource_id,
                    $office_id,
                    sanitize_text_field( $body['action'] ?? 'unknown' )
                ) );
                wp_schedule_single_event( time(), 'bsale_process_stock_webhook', [ $resource_id, $office_id ] );
                break;

            case 'price':
                $price_list_id = (int) ( $body['priceListId'] ?? 0 );
                $this->add_log( sprintf(
                    'WEBHOOK_RECEIVED  topic=price  variantId=%d  priceListId=%d',
                    $resource_id,
                    $price_list_id
                ) );
                wp_schedule_single_event( time(), 'bsale_process_price_webhook', [ $resource_id, $price_list_id ] );
                break;
        }

        spawn_cron();

        return new WP_REST_Response( [ 'status' => 'queued' ], 200 );
    }

    // -------------------------------------------------------------------------
    // Cron: sincronización de stock
    // -------------------------------------------------------------------------

    public function process_stock_webhook( int $variant_id, int $office_id = 0 ): void {
        $api = new Bsale_API();

        // Obtener SKU de la variante en Bsale
        $variant_data = $api->get_variant( $variant_id );
        if ( is_wp_error( $variant_data ) ) {
            $this->add_log( "STOCK_FETCH_ERROR  variantId={$variant_id}  error=" . $variant_data->get_error_message() );
            return;
        }

        $sku = $variant_data['code'] ?? '';
        if ( ! $sku ) {
            $this->add_log( "STOCK_NOT_MAPPED  variantId={$variant_id}  sin SKU en Bsale" );
            return;
        }

        // Consultar stock real (filtrado por bodega si viene en el webhook)
        $params = [ 'variantid' => $variant_id ];
        if ( $office_id ) {
            $params['officeid'] = $office_id;
        }

        $stock_data = $api->get( 'stocks.json', $params );
        if ( is_wp_error( $stock_data ) ) {
            $this->add_log( "STOCK_FETCH_ERROR  sku={$sku}  error=" . $stock_data->get_error_message() );
            return;
        }

        $qty = 0;
        foreach ( (array) ( $stock_data['items'] ?? [] ) as $item ) {
            $qty += (int) ( $item['quantityAvailable'] ?? 0 );
        }

        // Buscar producto WC por SKU
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $this->add_log( "STOCK_NOT_MAPPED  sku={$sku}  sin producto en WooCommerce" );
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        // Activar gestión de inventario si no está habilitada
        if ( ! $product->get_manage_stock() ) {
            $product->set_manage_stock( true );
            $product->save();
        }

        $prev_qty = (int) $product->get_stock_quantity();
        wc_update_product_stock( $product, $qty );

        $this->add_log( sprintf(
            'STOCK_UPDATED  sku=%s  wc_product=%d  stock=%d→%d',
            $sku,
            $product_id,
            $prev_qty,
            $qty
        ) );
    }

    // -------------------------------------------------------------------------
    // Cron: sincronización de precio
    // -------------------------------------------------------------------------

    public function process_price_webhook( int $variant_id, int $price_list_id ): void {
        $api      = new Bsale_API();
        $settings = get_option( BSALE_SYNC_OPTION, [] );

        // Solo procesar si la lista coincide con la configurada (o si no hay lista configurada)
        $configured = (int) ( $settings['price_list_id'] ?? 0 );
        if ( $configured && $price_list_id !== $configured ) {
            $this->add_log( "PRICE_SKIPPED  variantId={$variant_id}  priceListId={$price_list_id}  (lista no configurada)" );
            return;
        }

        // Obtener SKU de la variante en Bsale
        $variant_data = $api->get_variant( $variant_id );
        if ( is_wp_error( $variant_data ) ) {
            $this->add_log( "PRICE_FETCH_ERROR  variantId={$variant_id}  error=" . $variant_data->get_error_message() );
            return;
        }

        $sku = $variant_data['code'] ?? '';
        if ( ! $sku ) {
            $this->add_log( "PRICE_NOT_MAPPED  variantId={$variant_id}  sin SKU en Bsale" );
            return;
        }

        // Obtener precio desde la lista de precios
        $detail = $api->get_price_list_detail( $price_list_id, $variant_id );
        if ( is_wp_error( $detail ) || ! $detail ) {
            $this->add_log( "PRICE_FETCH_ERROR  sku={$sku}  sin detalle en lista {$price_list_id}" );
            return;
        }

        $price = (float) ( $detail['variantValueWithTaxes'] ?? 0 );
        if ( $price <= 0 ) {
            $this->add_log( "PRICE_INVALID  sku={$sku}  price={$price}" );
            return;
        }

        // Buscar producto WC por SKU
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $this->add_log( "PRICE_NOT_MAPPED  sku={$sku}  sin producto en WooCommerce" );
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) return;

        $prev_price = $product->get_regular_price();
        $product->set_regular_price( (string) $price );
        $product->save();

        wc_delete_product_transients( $product_id );

        $this->add_log( sprintf(
            'PRICE_UPDATED  sku=%s  wc_product=%d  price=%s→%s',
            $sku,
            $product_id,
            $prev_price,
            $price
        ) );
    }

    // -------------------------------------------------------------------------
    // Log de eventos
    // -------------------------------------------------------------------------

    public static function add_log( string $message ): void {
        $log   = get_option( 'bsale_event_log', [] );
        $entry = sprintf( '[%s] %s', wp_date( 'Y-m-d H:i:s' ), $message );

        array_unshift( $log, $entry );

        if ( count( $log ) > 100 ) {
            array_splice( $log, 100 );
        }

        update_option( 'bsale_event_log', $log, false );
    }

    public static function get_log(): array {
        return (array) get_option( 'bsale_event_log', [] );
    }

    public static function clear_log(): void {
        update_option( 'bsale_event_log', [], false );
    }
}
