<?php
/**
 * Interfaccia amministrativa per il plugin Farmadati WooCommerce.
 */
class Farmadati_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );

        // AJAX handlers
        add_action( 'wp_ajax_farmadati_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_farmadati_search', [ __CLASS__, 'ajax_search' ] );
        add_action( 'wp_ajax_farmadati_get_schema', [ __CLASS__, 'ajax_get_schema' ] );
        add_action( 'wp_ajax_farmadati_import_product', [ __CLASS__, 'ajax_import_product' ] );
        add_action( 'wp_ajax_farmadati_run_sync', [ __CLASS__, 'ajax_run_sync' ] );
        add_action( 'wp_ajax_farmadati_csv_sync', [ __CLASS__, 'ajax_csv_sync' ] );
        add_action( 'wp_ajax_farmadati_csv_enrich', [ __CLASS__, 'ajax_csv_enrich' ] );
        add_action( 'wp_ajax_farmadati_reset_enrich', [ __CLASS__, 'ajax_reset_enrich_errors' ] );
        add_action( 'wp_ajax_farmadati_enrich_images', [ __CLASS__, 'ajax_enrich_images' ] );
        add_action( 'admin_post_farmadati_upload_csv', [ __CLASS__, 'handle_csv_upload' ] );
    }

    public static function add_menu() {
        add_menu_page(
            'Farmadati',
            'Farmadati',
            'manage_woocommerce',
            'farmadati-wc',
            [ __CLASS__, 'render_main_page' ],
            'dashicons-pills',
            58
        );

        add_submenu_page(
            'farmadati-wc',
            'Cerca Prodotti',
            'Cerca Prodotti',
            'manage_woocommerce',
            'farmadati-wc',
            [ __CLASS__, 'render_main_page' ]
        );

        add_submenu_page(
            'farmadati-wc',
            'Sincronizzazione CSV',
            'Sincronizzazione CSV',
            'manage_woocommerce',
            'farmadati-wc-csv',
            [ __CLASS__, 'render_csv_page' ]
        );

        add_submenu_page(
            'farmadati-wc',
            'Sincronizzazione Farmadati',
            'Sincronizzazione',
            'manage_woocommerce',
            'farmadati-wc-sync',
            [ __CLASS__, 'render_sync_page' ]
        );

        add_submenu_page(
            'farmadati-wc',
            'Impostazioni',
            'Impostazioni',
            'manage_options',
            'farmadati-wc-settings',
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting( 'farmadati_wc_options_group', 'farmadati_wc_options', [
            'sanitize_callback' => [ __CLASS__, 'sanitize_options' ],
        ] );
    }

    public static function sanitize_options( $input ) {
        return [
            'username'     => sanitize_text_field( $input['username'] ?? '' ),
            'password'     => sanitize_text_field( $input['password'] ?? '' ),
            'default_table' => sanitize_text_field( $input['default_table'] ?? 'TE001' ),
            'sync_tables'  => array_map( 'sanitize_text_field', (array) ( $input['sync_tables'] ?? [ 'TE001' ] ) ),
            'auto_sync'    => (bool) ( $input['auto_sync'] ?? false ),
        ];
    }

    public static function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'farmadati' ) === false ) {
            return;
        }

        wp_enqueue_script(
            'farmadati-admin',
            FARMADATI_WC_PLUGIN_URL . 'assets/admin.js',
            [ 'jquery' ],
            FARMADATI_WC_VERSION,
            true
        );

        wp_localize_script( 'farmadati-admin', 'farmadatiAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'farmadati_nonce' ),
            'i18n'    => [
                'testing'        => __( 'Test in corso…', 'farmadati-wc' ),
                'testConnection' => __( 'Testa connessione', 'farmadati-wc' ),
                'noResults'      => __( 'Nessun risultato trovato.', 'farmadati-wc' ),
                'colCode'        => __( 'Codice', 'farmadati-wc' ),
                'colName'        => __( 'Nome prodotto', 'farmadati-wc' ),
                'colPrice'       => __( 'Prezzo', 'farmadati-wc' ),
                'colStatus'      => __( 'Stato', 'farmadati-wc' ),
                'colAction'      => __( 'Azioni', 'farmadati-wc' ),
                'colTable'       => __( 'Tabella', 'farmadati-wc' ),
                'colResult'      => __( 'Risultato', 'farmadati-wc' ),
                'inCommerce'     => __( 'In commercio', 'farmadati-wc' ),
                'outCommerce'    => __( 'Non in commercio', 'farmadati-wc' ),
                'importProduct'  => __( 'Importa', 'farmadati-wc' ),
                'update'         => __( 'Aggiorna', 'farmadati-wc' ),
                'edit'           => __( 'Modifica', 'farmadati-wc' ),
                'importing'      => __( 'Importazione…', 'farmadati-wc' ),
                'imported'       => __( 'Importato ✓', 'farmadati-wc' ),
                'runSync'        => __( 'Avvia sincronizzazione', 'farmadati-wc' ),
                'syncing'        => __( 'Sincronizzazione…', 'farmadati-wc' ),
                'syncRunning'    => __( 'Sincronizzazione in corso, attendere…', 'farmadati-wc' ),
                'page'           => __( 'Pagina', 'farmadati-wc' ),
                'prev'           => __( 'Prec', 'farmadati-wc' ),
                'next'           => __( 'Succ', 'farmadati-wc' ),
                'ajaxError'      => __( 'Errore di comunicazione con il server. Riprova.', 'farmadati-wc' ),
            ],
        ] );

        wp_enqueue_style( 'farmadati-admin', FARMADATI_WC_PLUGIN_URL . 'assets/admin.css', [], FARMADATI_WC_VERSION );
    }

    // -------------------------------------------------------------------------
    // Pagine admin
    // -------------------------------------------------------------------------

    public static function render_settings_page() {
        $options = get_option( 'farmadati_wc_options', [] );
        $tables_available = [
            'TE001' => 'TE001 – Parafarmaci e Dispositivi Medici',
            'TE002' => 'TE002 – Medicinali SOP/OTC (autorizzazione richiesta)',
            'TE003' => 'TE003 – Integratori Alimentari',
            'TE004' => 'TE004 – Cosmetici',
            'TE005' => 'TE005 – Dietetici',
            'TE006' => 'TE006 – Omeopatici',
            'TE007' => 'TE007 – Galenici e Preparazioni',
            'TE008' => 'TE008 – Presidi Medico-Chirurgici',
            'TE009' => 'TE009 – Erboristeria',
            'TE010' => 'TE010 – Articoli Sanitari',
            'TE011' => 'TE011 – Medicinali Veterinari',
            'TE012' => 'TE012 – Prodotti per Animali',
            'TE013' => 'TE013 – Diagnostici',
            'TE014' => 'TE014 – Ottico e Accessori',
            'TE015' => 'TE015 – Medicinali Veterinari SOP',
            'TE016' => 'TE016 – Prodotti per la Casa',
        ];
        $sync_tables = $options['sync_tables'] ?? [ 'TE001' ];
        ?>
        <div class="wrap">
            <h1>Farmadati – Impostazioni</h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'farmadati_wc_options_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="farmadati_username">Username Farmadati</label></th>
                        <td>
                            <input type="text" id="farmadati_username" name="farmadati_wc_options[username]"
                                value="<?php echo esc_attr( $options['username'] ?? '' ); ?>" class="regular-text" autocomplete="username" />
                            <p class="description">Username fornito da Farmadati Italia.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="farmadati_password">Password Farmadati</label></th>
                        <td>
                            <input type="password" id="farmadati_password" name="farmadati_wc_options[password]"
                                value="<?php echo esc_attr( $options['password'] ?? '' ); ?>" class="regular-text" autocomplete="current-password" />
                            <p class="description">Password fornita da Farmadati Italia. È usata anche come access key per le immagini.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="farmadati_default_table">Tabella predefinita</label></th>
                        <td>
                            <select id="farmadati_default_table" name="farmadati_wc_options[default_table]">
                                <?php foreach ( $tables_available as $code => $desc ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $options['default_table'] ?? 'TE001', $code ); ?>>
                                        <?php echo esc_html( $desc ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tabelle da sincronizzare</th>
                        <td>
                            <?php foreach ( $tables_available as $code => $desc ) : ?>
                                <label style="display:block;margin-bottom:4px;">
                                    <input type="checkbox" name="farmadati_wc_options[sync_tables][]"
                                        value="<?php echo esc_attr( $code ); ?>"
                                        <?php checked( in_array( $code, $sync_tables, true ) ); ?> />
                                    <?php echo esc_html( $desc ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Seleziona le tabelle da sincronizzare automaticamente ogni notte.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sincronizzazione automatica</th>
                        <td>
                            <label>
                                <input type="checkbox" name="farmadati_wc_options[auto_sync]" value="1"
                                    <?php checked( ! empty( $options['auto_sync'] ) ); ?> />
                                Attiva sincronizzazione giornaliera automatica (ogni notte alle 3:00)
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Salva impostazioni' ); ?>
            </form>

            <hr>
            <h2>Test connessione</h2>
            <p>
                <button class="button button-secondary" id="farmadati-test-btn">Testa connessione</button>
            </p>
            <div id="farmadati-test-result"></div>
        </div>
        <?php
    }

    public static function render_main_page() {
        $options      = get_option( 'farmadati_wc_options', [] );
        $default_table = $options['default_table'] ?? 'TE001';
        $tables = [
            'TE001' => 'Parafarmaci / Dispositivi Medici',
            'TE002' => 'Medicinali SOP/OTC',
            'TE003' => 'Integratori Alimentari',
            'TE004' => 'Cosmetici',
            'TE005' => 'Dietetici',
            'TE006' => 'Omeopatici',
            'TE007' => 'Galenici e Preparazioni',
            'TE008' => 'Presidi Medico-Chirurgici',
            'TE009' => 'Erboristeria',
            'TE010' => 'Articoli Sanitari',
            'TE011' => 'Medicinali Veterinari',
            'TE012' => 'Prodotti per Animali',
            'TE013' => 'Diagnostici',
            'TE014' => 'Ottico e Accessori',
            'TE015' => 'Medicinali Veterinari SOP',
            'TE016' => 'Prodotti per la Casa',
        ];
        ?>
        <div class="wrap">
            <h1>Farmadati – Cerca e Importa Prodotti</h1>

            <?php if ( empty( $options['username'] ) || empty( $options['password'] ) ) : ?>
                <div class="notice notice-warning">
                    <p>Configura prima le credenziali Farmadati nelle <a href="<?php echo esc_url( admin_url( 'admin.php?page=farmadati-wc-settings' ) ); ?>">Impostazioni</a>.</p>
                </div>
            <?php endif; ?>

            <div class="farmadati-search-box">
                <table class="form-table" style="max-width:700px;">
                    <tr>
                        <th><label for="fd-table">Tabella</label></th>
                        <td>
                            <select id="fd-table">
                                <?php foreach ( $tables as $code => $label ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $default_table, $code ); ?>>
                                        <?php echo esc_html( "$code – $label" ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="fd-search-query">Codice prodotto</label></th>
                        <td>
                            <input type="text" id="fd-search-query" class="regular-text" placeholder="Es: 012345678" />
                            <select id="fd-search-type" style="display:none;"></select>
                            <p class="description" id="fd-field-hint" style="margin-top:4px;"></p>
                        </td>
                    </tr>
                    <tr>
                        <th></th>
                        <td>
                            <button class="button button-primary" id="fd-search-btn">Cerca</button>
                            <span id="fd-search-spinner" class="spinner" style="float:none;margin-top:0;"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <div id="fd-results-wrap" style="display:none;">
                <h2>Risultati (<span id="fd-results-count">0</span> prodotti trovati)</h2>
                <div id="fd-results-notice"></div>
                <table class="wp-list-table widefat striped" id="fd-results-table">
                    <thead id="fd-results-head"></thead>
                    <tbody id="fd-results-body"></tbody>
                </table>
                <div id="fd-pagination" style="margin-top:10px;"></div>
            </div>
        </div>
        <?php
    }

    public static function render_sync_page() {
        $options     = get_option( 'farmadati_wc_options', [] );
        $last_cron   = get_option( 'farmadati_last_cron_run', 'Mai eseguita' );
        $cron_log    = get_option( 'farmadati_last_cron_log', [] );
        $sync_tables = $options['sync_tables'] ?? [ 'TE001' ];
        ?>
        <div class="wrap">
            <h1>Farmadati – Sincronizzazione Prodotti</h1>

            <div class="card" style="max-width:600px;padding:20px;">
                <h2>Stato sincronizzazione</h2>
                <p><strong>Ultima esecuzione automatica:</strong> <?php echo esc_html( $last_cron ); ?></p>
                <p><strong>Prossima esecuzione:</strong>
                    <?php
                    $next = wp_next_scheduled( 'farmadati_daily_sync' );
                    echo $next ? esc_html( date_i18n( 'd/m/Y H:i', $next ) ) : 'Non programmata';
                    ?>
                </p>

                <?php if ( ! empty( $cron_log ) ) : ?>
                    <h3>Ultimo log</h3>
                    <table class="widefat">
                        <thead><tr><th>Tabella</th><th>Risultato</th></tr></thead>
                        <tbody>
                        <?php foreach ( $cron_log as $table => $result ) : ?>
                            <tr>
                                <td><?php echo esc_html( $table ); ?></td>
                                <td><?php echo esc_html( is_array( $result ) ? json_encode( $result ) : $result ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <hr>
            <h2>Sincronizzazione manuale</h2>

            <div class="card" style="max-width:600px;padding:20px;">
                <p>Avvia manualmente la sincronizzazione per le tabelle selezionate nelle impostazioni.</p>
                <p>
                    <label>
                        <input type="radio" name="fd-sync-type" value="incremental" checked />
                        Differenziale (solo variazioni dall'ultima sync)
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="fd-sync-type" value="full" />
                        Completa (scarica tutta la tabella — può richiedere molto tempo)
                    </label>
                </p>
                <p>
                    <label><strong>Tabella:</strong>
                        <select id="fd-sync-table">
                            <?php foreach ( $sync_tables as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <button class="button button-primary" id="fd-sync-btn">Avvia sincronizzazione</button>
                <span id="fd-sync-spinner" class="spinner" style="float:none;margin-top:0;"></span>
            </div>

            <div id="fd-sync-result" style="margin-top:20px;"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_test_connection() {
        ob_start();
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            ob_end_clean();
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        if ( ! extension_loaded( 'soap' ) ) {
            ob_end_clean();
            wp_send_json_error( 'Estensione PHP SOAP non installata. Contatta il tuo hosting.' );
        }

        $options  = get_option( 'farmadati_wc_options', [] );
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            ob_end_clean();
            wp_send_json_error( 'Credenziali non configurate. Vai in Impostazioni e salva username e password.' );
        }

        try {
            $client = new Farmadati_Client( $username, $password );
            $result = $client->test_connection();
        } catch ( Throwable $t ) {
            ob_end_clean();
            wp_send_json_error( 'Errore PHP: ' . $t->getMessage() );
        }

        $stray = ob_get_clean();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() . ( $stray ? ' [output: ' . esc_html( substr( $stray, 0, 200 ) ) . ']' : '' ) );
        }

        wp_send_json_success( 'Connessione riuscita! Credenziali valide.' );
    }

    public static function ajax_get_schema() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        $table   = sanitize_text_field( $_POST['table'] ?? '' );
        $options = get_option( 'farmadati_wc_options', [] );
        $client  = new Farmadati_Client( $options['username'] ?? '', $options['password'] ?? '' );
        $result  = $client->get_schema( $table );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_search() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        $table       = sanitize_text_field( $_POST['table'] ?? 'TE001' );
        $field_key   = sanitize_text_field( $_POST['field_key'] ?? 'FDI_0004' );
        $query       = sanitize_text_field( $_POST['query'] ?? '' );
        $page        = max( 1, (int) ( $_POST['page'] ?? 1 ) );

        if ( empty( $query ) ) {
            wp_send_json_error( 'Termine di ricerca mancante.' );
        }

        $options  = get_option( 'farmadati_wc_options', [] );
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            wp_send_json_error( 'Credenziali Farmadati non configurate.' );
        }

        $client  = new Farmadati_Client( $username, $password );

        // Determina l'operatore in base al campo
        $operator = in_array( $field_key, [ 'FDI_0001', 'FDI_0002' ], true ) ? '=' : 'CONTIENE';

        $result = $client->execute_query(
            $table,
            [ 'ALL' ],
            [ [ 'key' => $field_key, 'operator' => $operator, 'value' => $query, 'or_group' => 0 ] ],
            [],
            $page,
            20
        );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Arricchisci con info su prodotti già importati
        foreach ( $result['records'] as &$rec ) {
            $sku               = $rec['FDI_0001'] ?? '';
            $existing_id       = $sku ? wc_get_product_id_by_sku( $sku ) : 0;
            $rec['_wc_id']     = $existing_id ?: null;
            $rec['_wc_edit']   = $existing_id ? get_edit_post_link( $existing_id ) : null;
        }
        unset( $rec );

        wp_send_json_success( $result );
    }

    public static function ajax_import_product() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        $table   = sanitize_text_field( $_POST['table'] ?? 'TE001' );
        $raw_json = wp_unslash( $_POST['record'] ?? '{}' );
        $raw      = json_decode( $raw_json, true );

        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Dati del record non validi.' );
        }

        // Sanifica i campi del record
        $record = [];
        foreach ( $raw as $k => $v ) {
            $key            = sanitize_text_field( $k );
            $record[ $key ] = sanitize_text_field( (string) $v );
        }

        $product_id = Farmadati_Sync::import_product( $record, $table );

        if ( is_wp_error( $product_id ) ) {
            wp_send_json_error( $product_id->get_error_message() );
        }

        wp_send_json_success( [
            'product_id' => $product_id,
            'edit_url'   => get_edit_post_link( $product_id, 'url' ),
            'view_url'   => get_permalink( $product_id ),
        ] );
    }

    public static function ajax_run_sync() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        // Aumenta i limiti per operazioni lunghe
        set_time_limit( 600 );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $type  = sanitize_text_field( $_POST['sync_type'] ?? 'incremental' );
        $table = sanitize_text_field( $_POST['table'] ?? 'TE001' );

        if ( $type === 'full' ) {
            $result = Farmadati_Sync::full_import( $table );
        } else {
            $result = Farmadati_Sync::incremental_sync( $table );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    // -------------------------------------------------------------------------
    // Pagina Sincronizzazione CSV
    // -------------------------------------------------------------------------

    public static function render_csv_page() {
        $stats     = Farmadati_CSV_Sync::get_stats();
        $last_sync = $stats['last_csv_sync'] ?? '';
        $last_log  = $stats['last_stats'] ?? [];
        ?>
        <div class="wrap farmadati-wrap">
            <h1><span class="dashicons dashicons-upload"></span> Farmadati – Sincronizzazione CSV</h1>

            <?php
            // Messaggio dopo upload
            $upload_msg = get_transient( 'farmadati_csv_upload_msg' );
            if ( $upload_msg ) {
                delete_transient( 'farmadati_csv_upload_msg' );
                $type = $upload_msg['type'];
                echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $upload_msg['text'] ) . '</p></div>';
            }
            ?>

            <div class="farmadati-section">
                <h2>Carica file CSV</h2>
                <p>Carica manualmente il file CSV esportato dal gestionale.<br>
                   Il file verrà salvato in: <code><?php echo esc_html( Farmadati_CSV_Sync::get_csv_path() ); ?></code></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      enctype="multipart/form-data">
                    <input type="hidden" name="action" value="farmadati_upload_csv" />
                    <?php wp_nonce_field( 'farmadati_upload_csv', 'farmadati_upload_nonce' ); ?>
                    <table class="form-table" style="max-width:600px;">
                        <tr>
                            <th><label for="farmadati_csv_file">File CSV</label></th>
                            <td>
                                <input type="file" name="farmadati_csv_file" id="farmadati_csv_file"
                                       accept=".csv,text/csv" required />
                                <p class="description">Il file deve avere il separatore <code>;</code> e i decimali con la virgola.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( 'Carica CSV', 'secondary', 'submit', false ); ?>
                </form>
            </div>

            <div class="farmadati-section">
                <h2>Stato</h2>
                <table class="form-table">
                    <tr>
                        <th>File CSV</th>
                        <td>
                            <?php if ( $stats['csv_exists'] ) : ?>
                                <span style="color:#007017;">&#10004; Presente</span>
                                &nbsp;–&nbsp;
                                Modificato: <?php echo esc_html( date_i18n( 'd/m/Y H:i', $stats['csv_modified'] ) ); ?>
                            <?php else : ?>
                                <span style="color:#cc1818;">&#10008; Non trovato</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Ultima sync CSV</th>
                        <td><?php echo $last_sync ? esc_html( $last_sync ) : 'Mai eseguita'; ?></td>
                    </tr>
                    <?php if ( ! empty( $last_log ) ) : ?>
                    <tr>
                        <th>Risultati ultima sync</th>
                        <td>
                            Nuovi: <strong><?php echo (int) ( $last_log['new'] ?? 0 ); ?></strong> &nbsp;|&nbsp;
                            Aggiornati: <strong><?php echo (int) ( $last_log['updated'] ?? 0 ); ?></strong> &nbsp;|&nbsp;
                            Errori: <strong><?php echo (int) ( $last_log['errors'] ?? 0 ); ?></strong>
                            (su <?php echo (int) ( $last_log['total'] ?? 0 ); ?> totali)
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Prodotti WooCommerce</th>
                        <td>
                            Sincronizzati da CSV: <strong><?php echo (int) $stats['synced']; ?></strong> &nbsp;|&nbsp;
                            Arricchiti da Farmadati: <strong><?php echo (int) $stats['enriched']; ?></strong>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="farmadati-section">
                <h2>Stadio 1 – Importa / Aggiorna da CSV</h2>
                <p>Legge <code><?php echo esc_html( Farmadati_CSV_Sync::CSV_SUBPATH ); ?></code> e crea/aggiorna
                   i prodotti WooCommerce con prezzi, stock e categorie del gestionale.<br>
                   <em>Veloce: non effettua chiamate a Farmadati. Sicuro da eseguire anche con molti prodotti.</em></p>
                <p>
                    <button class="button button-primary" id="fd-csv-sync-btn"
                        <?php disabled( ! $stats['csv_exists'] ); ?>>
                        Avvia sync CSV
                    </button>
                    <span id="fd-csv-sync-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
                </p>
                <div id="fd-csv-sync-result" style="margin-top:12px;"></div>
            </div>

            <div class="farmadati-section">
                <h2>Stadio 2 – Arricchimento da Farmadati</h2>
                <p>Per i prodotti sincronizzati ma non ancora arricchiti, recupera da Farmadati:
                   nome ufficiale, descrizione e immagine di prodotto.<br>
                   <em>Lento (una chiamata SOAP per prodotto). L'avanzamento è mostrato in tempo reale.</em></p>
                <p>
                    <button class="button button-primary" id="fd-enrich-btn">Avvia arricchimento</button>
                    <button class="button" id="fd-enrich-stop-btn" style="display:none;">Ferma</button>
                    <button class="button button-secondary" id="fd-enrich-reset-btn"
                            style="margin-left:20px;"
                            title="Cancella tutti i meta _farmadati_enriched e _farmadati_enrich_error così ogni prodotto viene rielaborato da capo">
                        Resetta arricchimento (riparti da capo)
                    </button>
                    <button class="button button-secondary" id="fd-images-only-btn"
                            style="margin-left:8px;"
                            title="Solo per prodotti già arricchiti ma privi di immagine (utile dopo correzione bug immagini)">
                        Aggiungi immagini mancanti
                    </button>
                    <span id="fd-enrich-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
                </p>
                <div id="fd-enrich-progress" style="margin-top:12px;display:none;">
                    <div style="background:#e0e0e0;border-radius:4px;height:20px;overflow:hidden;">
                        <div id="fd-enrich-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s;"></div>
                    </div>
                    <p id="fd-enrich-status" style="margin-top:6px;"></p>
                </div>
                <div id="fd-enrich-result" style="margin-top:12px;"></div>
            </div>
        </div>
        <?php
    }

    public static function ajax_csv_sync() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        set_time_limit( 300 );
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }

        $result = Farmadati_CSV_Sync::sync_all_from_csv();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_csv_enrich() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        set_time_limit( 120 );

        $result = Farmadati_CSV_Sync::enrich_batch();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_reset_enrich_errors() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        $deleted = Farmadati_CSV_Sync::reset_enrich_errors();
        wp_send_json_success( [ 'deleted' => $deleted, 'message' => "Reset completato: $deleted meta cancellati. I prodotti verranno riarricchiti al prossimo avvio." ] );
    }

    public static function ajax_enrich_images() {
        check_ajax_referer( 'farmadati_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permessi insufficienti.' );
        }

        set_time_limit( 120 );

        $result = Farmadati_CSV_Sync::enrich_images_batch();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    public static function handle_csv_upload() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Permessi insufficienti.' );
        }

        check_admin_referer( 'farmadati_upload_csv', 'farmadati_upload_nonce' );

        $redirect = admin_url( 'admin.php?page=farmadati-wc-csv' );

        if ( empty( $_FILES['farmadati_csv_file']['tmp_name'] ) ) {
            set_transient( 'farmadati_csv_upload_msg', [
                'type' => 'error',
                'text' => 'Nessun file ricevuto.',
            ], 60 );
            wp_redirect( $redirect );
            exit;
        }

        $file     = $_FILES['farmadati_csv_file'];
        $tmp      = $file['tmp_name'];
        $original = sanitize_file_name( $file['name'] );

        // Verifica estensione
        $ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
        if ( $ext !== 'csv' ) {
            set_transient( 'farmadati_csv_upload_msg', [
                'type' => 'error',
                'text' => 'Il file deve avere estensione .csv.',
            ], 60 );
            wp_redirect( $redirect );
            exit;
        }

        // Verifica che sia testo (MIME check di base)
        $mime = mime_content_type( $tmp );
        if ( $mime && strpos( $mime, 'text/' ) === false && $mime !== 'application/octet-stream' ) {
            set_transient( 'farmadati_csv_upload_msg', [
                'type' => 'error',
                'text' => 'Il file non sembra un CSV valido (tipo: ' . $mime . ').',
            ], 60 );
            wp_redirect( $redirect );
            exit;
        }

        $dest = Farmadati_CSV_Sync::get_csv_path();

        // Assicura che la cartella esista
        farmadati_wc_ensure_scambiodati_dir();

        if ( ! move_uploaded_file( $tmp, $dest ) ) {
            set_transient( 'farmadati_csv_upload_msg', [
                'type' => 'error',
                'text' => 'Impossibile salvare il file. Controlla i permessi della cartella scambiodati/.',
            ], 60 );
            wp_redirect( $redirect );
            exit;
        }

        $rows = max( 0, count( file( $dest ) ) - 1 );
        set_transient( 'farmadati_csv_upload_msg', [
            'type' => 'success',
            'text' => "File CSV caricato correttamente ($rows prodotti).",
        ], 60 );

        wp_redirect( $redirect );
        exit;
    }
}
