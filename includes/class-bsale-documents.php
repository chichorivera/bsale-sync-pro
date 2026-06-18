<?php
/**
 * Bsale_Documents
 * Emisión de boletas y facturas en Bsale al procesar un pedido WooCommerce.
 *
 * Trigger : woocommerce_order_status_processing
 * Guard   : meta _bsale_document_id previene duplicados
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Documents {

    private Bsale_API $api;
    private array $settings;

    public function __construct() {
        // Emisión automática al pasar a "procesando"
        add_action( 'woocommerce_order_status_processing', [ $this, 'emit_document' ], 10, 1 );

        // Panel admin en el pedido
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_order_panel' ] );

        // AJAX: reintentar emisión
        add_action( 'wp_ajax_bsale_retry_document', [ $this, 'ajax_retry_document' ] );

        // Estilos y scripts en admin pedidos
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_order_assets' ] );
    }

    // -------------------------------------------------------------------------
    // Emisión principal
    // -------------------------------------------------------------------------

    public function emit_document( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Guard: no emitir si ya existe un documento
        if ( $order->get_meta( '_bsale_document_id' ) ) return;

        // Lock por pedido: evita llamadas concurrentes (hook + AJAX simultáneos)
        $lock_key = 'bsale_emit_' . $order_id;
        if ( get_transient( $lock_key ) ) return;
        set_transient( $lock_key, 1, 60 );

        $this->api      = new Bsale_API();
        $this->settings = get_option( BSALE_SYNC_OPTION, [] );

        // Validar configuración mínima
        $error = $this->validate_settings();
        if ( $error ) {
            $this->save_error( $order, $error );
            delete_transient( $lock_key );
            return;
        }

        // Tipo de documento del pedido (boleta / factura)
        $doc_type    = $this->resolve_document_type( $order );
        $doc_type_id = $doc_type === 'factura'
            ? (int) ( $this->settings['factura_doc_type_id'] ?? 0 )
            : (int) ( $this->settings['boleta_doc_type_id'] ?? 0 );

        if ( $doc_type_id <= 0 ) {
            $this->save_error( $order, "documentTypeId no configurado para '{$doc_type}'. Revisa el tab Documentos." );
            delete_transient( $lock_key );
            return;
        }

        // Buscar o crear cliente en Bsale
        $client = $this->resolve_client( $order, $doc_type );
        if ( is_wp_error( $client ) ) {
            $this->save_error( $order, 'Cliente: ' . $client->get_error_message() );
            delete_transient( $lock_key );
            return;
        }

        // Construir y enviar documento
        $payload = $this->build_payload( $order, $doc_type, $doc_type_id, $client );

        self::log_sale( 'REQUEST', $order_id, $payload );

        $result = $this->api->create_document( $payload );

        self::log_sale( 'RESPONSE', $order_id, is_wp_error( $result )
            ? [ 'error' => $result->get_error_message() ]
            : $result
        );

        if ( is_wp_error( $result ) ) {
            $this->save_error( $order, $result->get_error_message() );
            error_log( '[Bsale] Error emisión orden #' . $order_id . ': ' . $result->get_error_message() );
            delete_transient( $lock_key );
            return;
        }

        // Guardar resultado exitoso — desregistrar el hook mientras guardamos para
        // evitar que el save del pedido vuelva a disparar emit_document
        remove_action( 'woocommerce_order_status_processing', [ $this, 'emit_document' ], 10 );

        $doc_id  = (string) ( $result['id'] ?? '' );
        $doc_url = $result['urlPdf'] ?? $result['url'] ?? '';

        $order->update_meta_data( '_bsale_document_id',   $doc_id );
        $order->update_meta_data( '_bsale_document_url',  $doc_url );
        $order->update_meta_data( '_bsale_document_type', $doc_type );
        $order->update_meta_data( '_bsale_emission_date', time() );
        $order->delete_meta_data( '_bsale_document_error' );
        $order->save();

        add_action( 'woocommerce_order_status_processing', [ $this, 'emit_document' ], 10 );
        delete_transient( $lock_key );

        $order->add_order_note(
            sprintf( 'Bsale: %s emitida. ID #%s', ucfirst( $doc_type ), $doc_id ),
            false,
            true
        );

        Bsale_Stock_Sync::add_log( sprintf(
            'DOCUMENT_CREATED  order_id=%d  bsale_id=%s  type=%s',
            $order_id,
            $doc_id,
            $doc_type
        ) );
    }

    // -------------------------------------------------------------------------
    // Resolver tipo de documento
    // -------------------------------------------------------------------------

    private function resolve_document_type( WC_Order $order ): string {
        $field   = $this->settings['doc_type_field'] ?? 'document_type';
        $factura = $this->settings['factura_value']  ?? 'factura';

        $value = $order->get_meta( $field ) ?: '';

        return $value === $factura ? 'factura' : 'boleta';
    }

    // -------------------------------------------------------------------------
    // Resolver cliente (buscar o crear)
    // -------------------------------------------------------------------------

    private function resolve_client( WC_Order $order, string $doc_type ): array {
        $is_factura = $doc_type === 'factura';

        $rut_field = $is_factura
            ? ( $this->settings['company_rut_field'] ?? 'billing_company_rut' )
            : ( $this->settings['rut_field'] ?? 'billing_rut' );

        $rut  = preg_replace( '/[^0-9kK]/', '', $order->get_meta( $rut_field ) ?: '' );
        $data = $this->build_client_data( $order, $doc_type, $rut );

        self::log_sale( 'CLIENT_DATA', $order->get_id(), $data );

        // Devolvemos los datos del pedido directamente — Bsale hace find-or-create
        // por code (RUT) en el endpoint de documentos. Evitamos usar {id: X} que
        // puede referenciar un cliente con estado interno inconsistente en Bsale.
        return $data;
    }

    private function build_client_data( WC_Order $order, string $doc_type, string $rut ): array {
        $is_factura = $doc_type === 'factura';

        $address = $is_factura
            ? ( $order->get_meta( 'billing_address_1_factura' ) ?: $order->get_billing_address_1() )
            : ( $order->get_meta( 'billing_address_user' )      ?: $order->get_billing_address_1() );

        $giro_field = $this->settings['giro_field'] ?? 'billing_giro';
        $giro       = $order->get_meta( $giro_field ) ?: 'Sin giro';

        $municipality = $order->get_billing_city();
        $city         = $this->state_code_to_name( $order->get_billing_state() );

        $data = [
            'firstName'       => $order->get_billing_first_name() ?: 'Consumidor',
            'lastName'        => $order->get_billing_last_name()  ?: 'Final',
            'email'           => $order->get_billing_email(),
            'address'         => $address,
            'municipality'    => $municipality,
            'city'            => $city,
            'activity'        => $is_factura ? $giro : 'Sin giro',
            'companyOrPerson' => $is_factura ? 1 : 0,
        ];

        // Solo incluir 'code' (RUT) si está presente — evita que Bsale haga
        // deduplicación por code vacío y devuelva un cliente existente sin nombre
        if ( ! empty( $rut ) ) {
            $data['code'] = $rut;
        }

        if ( $is_factura ) {
            $data['company'] = $order->get_billing_company();
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Construir payload del documento
    // -------------------------------------------------------------------------

    private function build_payload( WC_Order $order, string $doc_type, int $doc_type_id, array $client ): array {
        $now = time();

        $payload = [
            'documentTypeId' => $doc_type_id,
            'emissionDate'   => $now,
            'expirationDate' => $now,
            'declareSii'     => 1,
            'dispatch'       => ( $this->settings['dispatch_on_emit'] ?? true ) ? 1 : 0,
            'salesId'        => $order->get_id(), // previene duplicados en Bsale
            'sendEmail'      => 0,
            'client'         => isset( $client['id'] ) ? [ 'id' => (int) $client['id'] ] : $client,
            'details'        => $this->build_details( $order ),
            'payments'       => [
                [
                    'paymentTypeId' => 1,
                    'amount'        => (float) $order->get_total(),
                    'recordDate'    => $now,
                ],
            ],
        ];

        // Agregar officeId y priceListId solo si están configurados
        if ( ! empty( $this->settings['office_id'] ) ) {
            $payload['officeId'] = (int) $this->settings['office_id'];
        }
        if ( ! empty( $this->settings['price_list_id'] ) ) {
            $payload['priceListId'] = (int) $this->settings['price_list_id'];
        }

        return $payload;
    }

    private function build_details( WC_Order $order ): array {
        $details = [];

        foreach ( $order->get_items() as $item ) {
            $qty      = (int) $item->get_quantity();
            $subtotal = (float) $item->get_subtotal(); // precio original antes de descuentos
            $total    = (float) $item->get_total();    // precio final después de descuentos

            // Los precios en WooCommerce incluyen IVA. Bsale espera el valor neto
            // y calcula el IVA por su cuenta → dividimos por 1.19 (IVA chileno).
            $net_unit_value = $qty > 0 ? round( $subtotal / $qty / 1.19, 4 ) : 0;

            // Porcentaje de descuento efectivo (cubre precio oferta + cupón + combinados)
            $discount = 0;
            if ( $subtotal > 0 && $total < $subtotal ) {
                $discount = round( ( 1 - $total / $subtotal ) * 100, 2 );
            }

            $detail = [
                'netUnitValue' => $net_unit_value,
                'quantity'     => $qty,
                'discount'     => $discount,
                'comment'      => $item->get_name(),
            ];

            $product = $item->get_product();
            $sku     = $product ? $product->get_sku() : '';

            if ( $sku ) {
                $detail['code'] = $sku;
            }

            $details[] = $detail;
        }

        // Costo de envío como línea separada (solo si es mayor a cero)
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $details[] = [
                'netUnitValue' => round( $shipping_total, 4 ),
                'quantity'     => 1,
                'comment'      => 'Costo de Envío',
                'discount'     => 0,
            ];
        }

        return $details;
    }

    // -------------------------------------------------------------------------
    // Panel de Bsale en el pedido (admin)
    // -------------------------------------------------------------------------

    public function render_order_panel( WC_Order $order ): void {
        $doc_id    = $order->get_meta( '_bsale_document_id' );
        $doc_url   = $order->get_meta( '_bsale_document_url' );
        $doc_type  = $order->get_meta( '_bsale_document_type' );
        $doc_date  = $order->get_meta( '_bsale_emission_date' );
        $doc_error = $order->get_meta( '_bsale_document_error' );

        $nonce = wp_create_nonce( 'bsale_retry_' . $order->get_id() );
        ?>
        <div class="bsale-order-panel">
            <h3>Bsale</h3>

            <?php if ( $doc_id ) : ?>
                <p class="bsale-status bsale-status--ok">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php echo esc_html( ucfirst( $doc_type ?: 'Documento' ) ); ?> emitida
                </p>
                <ul class="bsale-order-meta">
                    <li><strong>ID Bsale:</strong> #<?php echo esc_html( $doc_id ); ?></li>
                    <?php if ( $doc_date ) : ?>
                        <li><strong>Fecha:</strong> <?php echo esc_html( date_i18n( 'd/m/Y H:i', (int) $doc_date ) ); ?></li>
                    <?php endif; ?>
                    <?php if ( $doc_url ) : ?>
                        <li>
                            <a href="<?php echo esc_url( $doc_url ); ?>" target="_blank" rel="noopener">
                                Ver PDF
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>

            <?php elseif ( $doc_error ) : ?>
                <p class="bsale-status bsale-status--error">
                    <span class="dashicons dashicons-warning"></span>
                    Error en la emisión
                </p>
                <p class="bsale-error-msg"><?php echo esc_html( $doc_error ); ?></p>
                <button
                    type="button"
                    class="button bsale-retry-btn"
                    data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    Reintentar emisión
                </button>
                <span class="bsale-retry-result"></span>

            <?php else : ?>
                <p class="bsale-status bsale-status--pending">
                    <span class="dashicons dashicons-clock"></span>
                    Pendiente de emisión
                </p>
                <button
                    type="button"
                    class="button bsale-retry-btn"
                    data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    Emitir ahora
                </button>
                <span class="bsale-retry-result"></span>
            <?php endif; ?>
        </div>
        <?php
    }

    public function enqueue_order_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) return;

        wp_enqueue_style(
            'bsale-order',
            BSALE_SYNC_URL . 'assets/css/bsale-admin.css',
            [],
            BSALE_SYNC_VERSION
        );

        wp_enqueue_script(
            'bsale-order',
            BSALE_SYNC_URL . 'assets/js/bsale-admin.js',
            [ 'jquery' ],
            BSALE_SYNC_VERSION,
            true
        );

        wp_localize_script( 'bsale-order', 'bsaleAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bsale_admin_nonce' ),
            'i18n'     => [
                'retrying' => 'Emitiendo…',
                'retry'    => 'Reintentar emisión',
                'emit'     => 'Emitir ahora',
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: reintentar emisión
    // -------------------------------------------------------------------------

    public function ajax_retry_document(): void {
        $order_id = absint( $_POST['order_id'] ?? 0 );

        check_ajax_referer( 'bsale_retry_' . $order_id, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => 'ID de pedido inválido.' ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
        }

        // Limpiar estado previo — desregistrar hook para que el save no dispare emit_document
        remove_action( 'woocommerce_order_status_processing', [ $this, 'emit_document' ], 10 );
        $order->delete_meta_data( '_bsale_document_id' );
        $order->delete_meta_data( '_bsale_document_error' );
        $order->save();
        add_action( 'woocommerce_order_status_processing', [ $this, 'emit_document' ], 10 );

        $this->emit_document( $order_id );

        // Leer resultado
        $order  = wc_get_order( $order_id );
        $doc_id = $order->get_meta( '_bsale_document_id' );
        $error  = $order->get_meta( '_bsale_document_error' );

        if ( $doc_id ) {
            wp_send_json_success( [ 'message' => 'Documento emitido: #' . $doc_id ] );
        } else {
            wp_send_json_error( [ 'message' => $error ?: 'Error desconocido.' ] );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validate_settings(): string {
        $s = $this->settings;

        if ( empty( $s['access_token'] ) ) {
            return 'Token de API no configurado.';
        }

        return '';
    }

    private function save_error( WC_Order $order, string $message ): void {
        $order->update_meta_data( '_bsale_document_error', $message );
        $order->save();
        error_log( '[Bsale] Orden #' . $order->get_id() . ': ' . $message );
    }

    private static function log_sale( string $label, int $order_id, mixed $data ): void {
        $settings = get_option( BSALE_SYNC_OPTION, [] );
        if ( empty( $settings['sales_log_enabled'] ) ) return;

        $log_dir = BSALE_SYNC_DIR . 'log/';
        if ( ! is_dir( $log_dir ) ) return;

        $log_file = $log_dir . 'sales-' . wp_date( 'Y-m-d' ) . '.log';
        $entry    = sprintf(
            "[%s] ORDER#%d %s:\n%s\n\n",
            wp_date( 'Y-m-d H:i:s' ),
            $order_id,
            $label,
            wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
    }

    /**
     * Convierte código de región WooCommerce (CL-RM) a nombre completo.
     * Comparte el mismo mapa que class-wayka-checkout.php.
     */
    private function state_code_to_name( string $code ): string {
        if ( ! str_starts_with( $code, 'CL-' ) ) return $code;

        $map = [
            'CL-AP' => 'Arica y Parinacota',
            'CL-TA' => 'Tarapacá',
            'CL-AN' => 'Antofagasta',
            'CL-AT' => 'Atacama',
            'CL-CO' => 'Coquimbo',
            'CL-VS' => 'Valparaíso',
            'CL-RM' => 'Región Metropolitana de Santiago',
            'CL-LI' => 'Libertador General Bernardo O\'Higgins',
            'CL-ML' => 'Maule',
            'CL-NB' => 'Ñuble',
            'CL-BI' => 'Biobío',
            'CL-AR' => 'La Araucanía',
            'CL-LR' => 'Los Ríos',
            'CL-LL' => 'Los Lagos',
            'CL-AI' => 'Aysén del General Carlos Ibañez del Campo',
            'CL-MA' => 'Magallanes',
        ];

        return $map[ $code ] ?? $code;
    }
}
