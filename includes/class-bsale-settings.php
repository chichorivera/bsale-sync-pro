<?php
/**
 * Bsale_Settings
 * Panel de configuración del plugin en WooCommerce > Bsale Sync Pro.
 *
 * Tabs: Conexión · Documentos · Mapeo de campos · Webhook
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Settings {

    private string $option_key = BSALE_SYNC_OPTION;
    private string $menu_slug  = 'bsale-sync-pro';

    public function __construct() {
        add_action( 'admin_menu',                          [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts',               [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_bsale_save_settings',      [ $this, 'save' ] );
        add_action( 'wp_ajax_bsale_verify_token',          [ $this, 'ajax_verify_token' ] );
        add_action( 'wp_ajax_bsale_load_select_options',   [ $this, 'ajax_load_select_options' ] );
        add_action( 'wp_ajax_bsale_regenerate_secret',     [ $this, 'ajax_regenerate_secret' ] );
        add_action( 'wp_ajax_bsale_clear_log',             [ $this, 'ajax_clear_log' ] );
    }

    // -------------------------------------------------------------------------
    // Menú
    // -------------------------------------------------------------------------

    public function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Bsale Sync Pro',
            'Bsale Sync Pro',
            'manage_woocommerce',
            $this->menu_slug,
            [ $this, 'render' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, $this->menu_slug ) ) return;

        wp_enqueue_style(
            'bsale-admin',
            BSALE_SYNC_URL . 'assets/css/bsale-admin.css',
            [],
            BSALE_SYNC_VERSION
        );

        wp_enqueue_script(
            'bsale-admin',
            BSALE_SYNC_URL . 'assets/js/bsale-admin.js',
            [ 'jquery' ],
            BSALE_SYNC_VERSION,
            true
        );

        wp_localize_script( 'bsale-admin', 'bsaleAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bsale_admin_nonce' ),
            'i18n'     => [
                'verifying'      => 'Verificando…',
                'verify'         => 'Verificar conexión',
                'token_empty'    => 'Ingresa un token primero.',
                'loading'        => 'Cargando…',
                'select_default' => '— Selecciona —',
                'load_error'     => 'Error al cargar. Verifica el token.',
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Render principal
    // -------------------------------------------------------------------------

    public function render(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;

        $settings   = $this->get_settings();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'conexion';
        $saved      = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
        ?>
        <div class="wrap bsale-wrap">
            <h1>Bsale Sync Pro</h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper bsale-tabs">
                <?php foreach ( $this->tabs() as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page={$this->menu_slug}&tab={$slug}" ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="bsale-tab-content">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'bsale_save_settings', 'bsale_nonce' ); ?>
                    <input type="hidden" name="action"           value="bsale_save_settings">
                    <input type="hidden" name="bsale_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

                    <?php
                    match ( $active_tab ) {
                        'documentos' => $this->render_tab_documentos( $settings ),
                        'mapeo'      => $this->render_tab_mapeo( $settings ),
                        'webhook'    => $this->render_tab_webhook( $settings ),
                        default      => $this->render_tab_conexion( $settings ),
                    };
                    ?>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Guardar cambios</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Conexión
    // -------------------------------------------------------------------------

    private function render_tab_conexion( array $settings ): void {
        $token = $settings['access_token'] ?? '';
        ?>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="bsale_access_token">Token de API</label>
                </th>
                <td>
                    <div class="bsale-token-row">
                        <input
                            type="password"
                            id="bsale_access_token"
                            name="bsale_access_token"
                            value="<?php echo esc_attr( $token ); ?>"
                            class="regular-text"
                            autocomplete="new-password"
                            placeholder="Tu access token de Bsale"
                        >
                        <button type="button" id="bsale-verify-token" class="button button-secondary">
                            Verificar conexión
                        </button>
                    </div>
                    <span id="bsale-verify-result" class="bsale-verify-result"></span>
                    <p class="description">
                        Encuéntralo en tu cuenta Bsale: <em>Configuración → API → Access Token</em>.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Documentos
    // -------------------------------------------------------------------------

    private function render_tab_documentos( array $settings ): void {
        if ( empty( $settings['access_token'] ) ) {
            echo '<div class="notice notice-warning inline" style="margin:16px 0"><p>'
                . 'Configura el <strong>Token de API</strong> en la pestaña <strong>Conexión</strong> primero.'
                . '</p></div>';
            return;
        }
        ?>
        <p class="bsale-tab-description">
            Asocia los tipos de documento y parámetros de Bsale que se usarán al emitir boletas y facturas.
        </p>

        <h3 class="bsale-section-heading">Tipos de documento</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bsale_boleta_doc_type_id">Tipo de documento — Boleta</label></th>
                <td>
                    <?php $this->render_dynamic_select( 'bsale_boleta_doc_type_id', 'document_types', $settings['boleta_doc_type_id'] ); ?>
                    <p class="description">Tipo de documento en Bsale que corresponde a <strong>boleta electrónica</strong>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsale_factura_doc_type_id">Tipo de documento — Factura</label></th>
                <td>
                    <?php $this->render_dynamic_select( 'bsale_factura_doc_type_id', 'document_types', $settings['factura_doc_type_id'] ); ?>
                    <p class="description">Tipo de documento en Bsale que corresponde a <strong>factura electrónica</strong>.</p>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">Precios y bodega</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bsale_price_list_id">Lista de precio</label></th>
                <td>
                    <?php $this->render_dynamic_select( 'bsale_price_list_id', 'price_lists', $settings['price_list_id'] ); ?>
                    <p class="description">Lista de precio de Bsale aplicada al emitir documentos.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsale_office_id">Bodega activa</label></th>
                <td>
                    <?php $this->render_dynamic_select( 'bsale_office_id', 'offices', $settings['office_id'] ); ?>
                    <p class="description">Bodega desde la que se descuenta el stock al emitir.</p>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">Opciones</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row">Reducir stock al emitir</th>
                <td>
                    <label class="bsale-checkbox-label">
                        <input
                            type="checkbox"
                            name="bsale_dispatch_on_emit"
                            value="1"
                            <?php checked( $settings['dispatch_on_emit'], true ); ?>
                        >
                        Enviar <code>dispatch: 1</code> en el documento (descuenta stock en Bsale)
                    </label>
                    <p class="description">
                        Activa esto si Bsale es la fuente de verdad del stock y el webhook actualiza WooCommerce.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Log de ventas en archivo</th>
                <td>
                    <label class="bsale-checkbox-label">
                        <input
                            type="checkbox"
                            name="bsale_sales_log_enabled"
                            value="1"
                            <?php checked( $settings['sales_log_enabled'], true ); ?>
                        >
                        Guardar request y response de cada venta en <code>log/sales-YYYY-MM-DD.log</code>
                    </label>
                    <p class="description">
                        Útil para depurar errores. Los archivos quedan dentro del directorio <code>log/</code> del plugin.
                        Desactívalo en producción cuando no lo necesites.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Renderiza un <select> que JS poblará vía AJAX.
     * data-source indica qué endpoint cargar.
     * data-saved indica el valor a preseleccionar.
     */
    private function render_dynamic_select( string $name, string $source, string $saved_value ): void {
        ?>
        <select
            id="<?php echo esc_attr( $name ); ?>"
            name="<?php echo esc_attr( $name ); ?>"
            class="bsale-dynamic-select"
            data-source="<?php echo esc_attr( $source ); ?>"
            data-saved="<?php echo esc_attr( $saved_value ); ?>"
            disabled
        >
            <option value="">Cargando…</option>
        </select>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Mapeo de campos
    // -------------------------------------------------------------------------

    private function render_tab_mapeo( array $settings ): void {
        $billing_fields = $this->billing_fields();
        ?>
        <p class="bsale-tab-description">
            Indica qué campos del pedido WooCommerce contienen cada dato necesario para emitir en Bsale.
        </p>

        <h3 class="bsale-section-heading">Tipo de documento</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bsale_doc_type_field">Campo tipo de documento</label></th>
                <td>
                    <select id="bsale_doc_type_field" name="bsale_doc_type_field" class="regular-text">
                        <?php foreach ( $billing_fields as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['doc_type_field'], $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Campo del pedido que indica si el cliente quiere boleta o factura.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsale_boleta_value">Valor = Boleta</label></th>
                <td>
                    <input
                        type="text"
                        id="bsale_boleta_value"
                        name="bsale_boleta_value"
                        value="<?php echo esc_attr( $settings['boleta_value'] ); ?>"
                        class="regular-text"
                    >
                    <p class="description">Valor del campo anterior que representa <strong>boleta</strong>. Ej: <code>boleta</code>.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsale_factura_value">Valor = Factura</label></th>
                <td>
                    <input
                        type="text"
                        id="bsale_factura_value"
                        name="bsale_factura_value"
                        value="<?php echo esc_attr( $settings['factura_value'] ); ?>"
                        class="regular-text"
                    >
                    <p class="description">Valor del campo anterior que representa <strong>factura</strong>. Ej: <code>factura</code>.</p>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">RUT del cliente</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bsale_rut_field">Campo RUT — Boleta</label></th>
                <td>
                    <select id="bsale_rut_field" name="bsale_rut_field" class="regular-text">
                        <?php foreach ( $billing_fields as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['rut_field'], $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Campo que contiene el RUT personal del cliente (para boleta).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bsale_company_rut_field">Campo RUT — Factura</label></th>
                <td>
                    <select id="bsale_company_rut_field" name="bsale_company_rut_field" class="regular-text">
                        <?php foreach ( $billing_fields as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['company_rut_field'], $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Campo que contiene el RUT de empresa del cliente (para factura).</p>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">Otros campos</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bsale_giro_field">Campo Giro comercial</label></th>
                <td>
                    <select id="bsale_giro_field" name="bsale_giro_field" class="regular-text">
                        <?php foreach ( $billing_fields as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['giro_field'], $key ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Se envía como <code>activity</code> del cliente en documentos de factura.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Guardar
    // -------------------------------------------------------------------------

    public function save(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.' );
        check_admin_referer( 'bsale_save_settings', 'bsale_nonce' );

        $tab      = sanitize_key( $_POST['bsale_active_tab'] ?? 'conexion' );
        $settings = $this->get_settings();

        switch ( $tab ) {
            case 'conexion':
                $settings['access_token'] = sanitize_text_field( $_POST['bsale_access_token'] ?? '' );
                // Al cambiar el token, invalidar caché de la API
                delete_transient( 'bsale_cache_document_types' );
                delete_transient( 'bsale_cache_price_lists' );
                delete_transient( 'bsale_cache_offices' );
                break;

            case 'documentos':
                $settings['boleta_doc_type_id']  = sanitize_text_field( $_POST['bsale_boleta_doc_type_id'] ?? '' );
                $settings['factura_doc_type_id'] = sanitize_text_field( $_POST['bsale_factura_doc_type_id'] ?? '' );
                $settings['price_list_id']        = sanitize_text_field( $_POST['bsale_price_list_id'] ?? '' );
                $settings['office_id']            = sanitize_text_field( $_POST['bsale_office_id'] ?? '' );
                $settings['dispatch_on_emit']   = isset( $_POST['bsale_dispatch_on_emit'] );
                $settings['sales_log_enabled'] = isset( $_POST['bsale_sales_log_enabled'] );
                break;

            case 'mapeo':
                $settings['doc_type_field']     = sanitize_key( $_POST['bsale_doc_type_field'] ?? 'document_type' );
                $settings['boleta_value']        = sanitize_text_field( $_POST['bsale_boleta_value'] ?? 'boleta' );
                $settings['factura_value']       = sanitize_text_field( $_POST['bsale_factura_value'] ?? 'factura' );
                $settings['rut_field']           = sanitize_key( $_POST['bsale_rut_field'] ?? 'billing_rut' );
                $settings['company_rut_field']   = sanitize_key( $_POST['bsale_company_rut_field'] ?? 'billing_company_rut' );
                $settings['giro_field']          = sanitize_key( $_POST['bsale_giro_field'] ?? 'billing_giro' );
                break;

            case 'webhook':
                $secret = sanitize_text_field( $_POST['bsale_webhook_secret'] ?? '' );
                if ( ! empty( $secret ) ) {
                    $settings['webhook_secret'] = $secret;
                }
                break;
        }

        update_option( $this->option_key, $settings );

        wp_safe_redirect( add_query_arg( [
            'page'  => $this->menu_slug,
            'tab'   => $tab,
            'saved' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Tab: Webhook
    // -------------------------------------------------------------------------

    private function render_tab_webhook( array $settings ): void {
        $secret      = $settings['webhook_secret'] ?? '';
        $webhook_url = rest_url( 'bsale/v1/stock' ) . ( $secret ? '?secret=' . rawurlencode( $secret ) : '' );
        $last_event  = (int) get_option( 'bsale_last_webhook', 0 );
        $log_entries = Bsale_Stock_Sync::get_log();
        ?>
        <p class="bsale-tab-description">
            Registra la URL del webhook en tu cuenta Bsale para mantener el stock sincronizado en tiempo real.
        </p>

        <h3 class="bsale-section-heading">Endpoint del webhook</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row">URL del webhook</th>
                <td>
                    <div class="bsale-webhook-url-row">
                        <input
                            type="text"
                            id="bsale-webhook-url"
                            value="<?php echo esc_attr( $webhook_url ); ?>"
                            class="large-text"
                            readonly
                        >
                        <button type="button" id="bsale-copy-webhook-url" class="button button-secondary">
                            Copiar
                        </button>
                    </div>
                    <p class="description">
                        Pega esta URL en <em>Bsale → Configuración → Notificaciones webhook → Stock</em>.
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">Clave secreta</th>
                <td>
                    <div class="bsale-token-row">
                        <input
                            type="text"
                            id="bsale-webhook-secret"
                            name="bsale_webhook_secret"
                            value="<?php echo esc_attr( $secret ); ?>"
                            class="regular-text"
                        >
                        <button type="button" id="bsale-regenerate-secret" class="button button-secondary">
                            Regenerar
                        </button>
                    </div>
                    <span id="bsale-regenerate-result" class="bsale-verify-result"></span>
                    <p class="description">
                        Al regenerar se actualiza la URL del webhook. Recuerda actualizarla en Bsale.
                    </p>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">Estado</h3>
        <table class="form-table bsale-form-table" role="presentation">
            <tr>
                <th scope="row">Último evento recibido</th>
                <td>
                    <?php if ( $last_event ) : ?>
                        <span class="bsale-status bsale-status--ok">
                            <?php echo esc_html( date_i18n( 'd/m/Y H:i:s', $last_event ) ); ?>
                        </span>
                    <?php else : ?>
                        <span class="bsale-status bsale-status--pending">Sin eventos aún</span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h3 class="bsale-section-heading">
            Log de eventos
            <button type="button" id="bsale-clear-log" class="button button-secondary bsale-clear-log-btn">
                Limpiar log
            </button>
        </h3>
        <div id="bsale-log-wrap">
            <?php if ( $log_entries ) : ?>
                <textarea class="bsale-log-textarea" readonly><?php
                    echo esc_textarea( implode( "\n", $log_entries ) );
                ?></textarea>
            <?php else : ?>
                <p class="description">Sin eventos registrados.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: regenerar webhook secret
    // -------------------------------------------------------------------------

    public function ajax_regenerate_secret(): void {
        check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $settings                    = $this->get_settings();
        $secret                      = wp_generate_password( 40, false );
        $settings['webhook_secret']  = $secret;
        update_option( $this->option_key, $settings );

        $url = rest_url( 'bsale/v1/stock' ) . '?secret=' . rawurlencode( $secret );

        wp_send_json_success( [
            'secret'  => $secret,
            'url'     => $url,
            'message' => 'Clave regenerada. Actualiza la URL en Bsale.',
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: limpiar log de eventos
    // -------------------------------------------------------------------------

    public function ajax_clear_log(): void {
        check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        Bsale_Stock_Sync::clear_log();
        wp_send_json_success( [ 'message' => 'Log borrado.' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: verificar token
    // -------------------------------------------------------------------------

    public function ajax_verify_token(): void {
        check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $token = sanitize_text_field( $_POST['token'] ?? '' );

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => 'Token vacío.' ] );
        }

        $api    = new Bsale_API( $token );
        $result = $api->get_document_types();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => 'Token inválido: ' . $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Conexión exitosa ✓' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: cargar opciones de select dinámico
    // -------------------------------------------------------------------------

    public function ajax_load_select_options(): void {
        check_ajax_referer( 'bsale_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $source = sanitize_key( $_POST['source'] ?? '' );
        $api    = new Bsale_API();

        $result = match ( $source ) {
            'document_types' => $api->get_document_types(),
            'price_lists'    => $api->get_price_lists(),
            'offices'        => $api->get_offices(),
            default          => new WP_Error( 'invalid_source', 'Fuente inválida.' ),
        };

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        $items = array_map(
            fn( $item ) => [ 'id' => (string) $item['id'], 'name' => $item['name'] ],
            $result['items'] ?? []
        );

        wp_send_json_success( [ 'items' => $items ] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function get_settings(): array {
        return wp_parse_args(
            get_option( $this->option_key, [] ),
            [
                // Conexión
                'access_token'        => '',
                // Documentos
                'boleta_doc_type_id'  => '',
                'factura_doc_type_id' => '',
                'price_list_id'       => '',
                'office_id'           => '',
                'dispatch_on_emit'    => true,
                'sales_log_enabled'   => false,
                // Mapeo
                'doc_type_field'      => 'document_type',
                'boleta_value'        => 'boleta',
                'factura_value'       => 'factura',
                'rut_field'           => 'billing_rut',
                'company_rut_field'   => 'billing_company_rut',
                'giro_field'          => 'billing_giro',
                // Webhook (Fase 4)
                'webhook_secret'      => '',
            ]
        );
    }

    private function tabs(): array {
        return [
            'conexion'   => 'Conexión',
            'documentos' => 'Documentos',
            'mapeo'      => 'Mapeo de campos',
            'webhook'    => 'Webhook',
        ];
    }

    /**
     * Campos del pedido disponibles para mapeo.
     * Incluye campos estándar de WooCommerce y los campos custom del tema Wayka.
     */
    private function billing_fields(): array {
        return [
            // Estándar WooCommerce
            'billing_first_name'      => 'billing_first_name',
            'billing_last_name'       => 'billing_last_name',
            'billing_email'           => 'billing_email',
            'billing_phone'           => 'billing_phone',
            'billing_address_1'       => 'billing_address_1',
            'billing_company'         => 'billing_company',
            'billing_state'           => 'billing_state',
            'billing_city'            => 'billing_city',
            // Custom Wayka
            'billing_rut'             => 'billing_rut (RUT personal)',
            'billing_company_rut'     => 'billing_company_rut (RUT empresa)',
            'billing_giro'            => 'billing_giro (Giro comercial)',
            'billing_address_user'    => 'billing_address_user (Dirección personal)',
            'billing_address_1_factura' => 'billing_address_1_factura (Dirección comercial)',
            'document_type'           => 'document_type (Boleta / Factura)',
        ];
    }
}
