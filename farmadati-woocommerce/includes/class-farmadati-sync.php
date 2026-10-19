<?php
/**
 * Logica di sincronizzazione Farmadati → WooCommerce.
 */
class Farmadati_Sync {

    public static function init() {
        add_action( 'farmadati_daily_sync', [ __CLASS__, 'run_daily_sync' ] );
    }

    // -------------------------------------------------------------------------
    // Importazione singolo prodotto da record Farmadati
    // -------------------------------------------------------------------------

    /**
     * Importa o aggiorna un prodotto WooCommerce da un record Farmadati.
     *
     * @param array  $record   Record associativo con chiavi FDI_*
     * @param string $table    Codice tabella (TE001, TE002, ecc.)
     * @return int|WP_Error    ID del prodotto WooCommerce
     */
    public static function import_product( $record, $table = 'TE001' ) {
        $sku = sanitize_text_field( $record['FDI_0001'] ?? '' );
        if ( empty( $sku ) ) {
            return new WP_Error( 'missing_sku', 'Codice prodotto mancante.' );
        }

        // Recupera il prodotto esistente per SKU
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku( $sku );
        }

        // Nome prodotto
        $name = sanitize_text_field( $record['FDI_0004'] ?? '' );
        if ( ! empty( $name ) ) {
            $product->set_name( $name );
        }

        // Prezzo
        $price = (float) str_replace( ',', '.', $record['FDI_9007'] ?? 0 );
        if ( $price > 0 ) {
            $product->set_regular_price( number_format( $price, 2, '.', '' ) );
        }

        // Aliquota IVA
        $iva = (int) ( $record['FDI_0248'] ?? 0 );
        if ( $iva > 0 ) {
            // Cerca o crea la classe fiscale corrispondente all'aliquota
            $tax_class = self::get_or_create_tax_class( $iva );
            if ( ! empty( $tax_class ) ) {
                $product->set_tax_class( $tax_class );
            }
        }

        // Flag commercio: S = in commercio
        $in_commerce = strtoupper( $record['FDI_0245'] ?? '' ) === 'S';
        $product->set_catalog_visibility( $in_commerce ? 'visible' : 'hidden' );
        $product->set_status( $in_commerce ? 'publish' : 'draft' );

        // Descrizione (breve) — se disponibile dalla tabella (TE005/TE008)
        if ( isset( $record['FDI_4887'] ) && ! empty( $record['FDI_4887'] ) ) {
            $product->set_description( wp_kses_post( $record['FDI_4887'] ) );
        }
        if ( isset( $record['FDI_1702'] ) && ! empty( $record['FDI_1702'] ) ) {
            $product->set_description( wp_kses_post( $record['FDI_1702'] ) );
        }

        // EAN/Barcode salvato come meta custom
        if ( ! empty( $record['FDI_0002'] ) ) {
            $product->update_meta_data( '_ean', sanitize_text_field( $record['FDI_0002'] ) );
        }

        // Codice ditta (brand)
        if ( ! empty( $record['FDI_0040'] ) ) {
            $product->update_meta_data( '_farmadati_brand_code', sanitize_text_field( $record['FDI_0040'] ) );
        }

        // Tabella di origine
        $product->update_meta_data( '_farmadati_table', $table );
        $product->update_meta_data( '_farmadati_last_sync', current_time( 'mysql' ) );

        // Tipo variazione (per sync differenziale)
        if ( isset( $record['TIPO_VARIAZIONE'] ) ) {
            $tipo = strtoupper( $record['TIPO_VARIAZIONE'] );
            if ( $tipo === 'A' ) {
                // Prodotto annullato → metti in bozza
                $product->set_status( 'draft' );
                $product->set_catalog_visibility( 'hidden' );
            }
        }

        $product_id = $product->save();

        if ( ! $product_id ) {
            return new WP_Error( 'save_failed', "Impossibile salvare il prodotto con SKU: $sku" );
        }

        // Assegna categoria
        self::assign_category( $product_id, $table );

        return $product_id;
    }

    // -------------------------------------------------------------------------
    // Sincronizzazione bulk
    // -------------------------------------------------------------------------

    /**
     * Importa tutti i prodotti di una tabella Farmadati.
     * Usato per il caricamento iniziale.
     *
     * @param string $table
     * @return array  ['imported'=>int, 'updated'=>int, 'errors'=>int]
     */
    public static function full_import( $table = 'TE001' ) {
        $options  = get_option( 'farmadati_wc_options', [] );
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'no_credentials', 'Credenziali Farmadati non configurate.' );
        }

        $client  = new Farmadati_Client( $username, $password );
        $records = $client->get_dataset( $table );

        if ( is_wp_error( $records ) ) {
            return $records;
        }

        $stats = [ 'imported' => 0, 'updated' => 0, 'errors' => 0 ];

        foreach ( $records as $record ) {
            $sku        = $record['FDI_0001'] ?? '';
            $existed    = ! empty( $sku ) && wc_get_product_id_by_sku( $sku );
            $product_id = self::import_product( $record, $table );

            if ( is_wp_error( $product_id ) ) {
                $stats['errors']++;
            } elseif ( $existed ) {
                $stats['updated']++;
            } else {
                $stats['imported']++;
            }
        }

        update_option( 'farmadati_last_full_import_' . $table, current_time( 'mysql' ) );

        return $stats;
    }

    /**
     * Sincronizzazione differenziale giornaliera.
     * Scarica solo le variazioni dall'ultimo aggiornamento.
     *
     * @param string $table
     * @return array|WP_Error
     */
    public static function incremental_sync( $table = 'TE001' ) {
        $options  = get_option( 'farmadati_wc_options', [] );
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'no_credentials', 'Credenziali Farmadati non configurate.' );
        }

        $client       = new Farmadati_Client( $username, $password );
        $last_sync    = get_option( 'farmadati_last_sync_' . $table, '' );
        // Formato richiesto da Farmadati: dd/mm/yyyyHH:MM
        $last_update  = '';
        if ( ! empty( $last_sync ) ) {
            $ts          = strtotime( $last_sync );
            $last_update = date( 'd/m/Y', $ts ) . date( 'H:i', $ts );
        }

        $records = $client->get_dataset_changes( $table, $last_update );

        if ( is_wp_error( $records ) ) {
            return $records;
        }

        $stats = [ 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0 ];

        foreach ( $records as $record ) {
            $tipo       = strtoupper( $record['TIPO_VARIAZIONE'] ?? '' );
            $product_id = self::import_product( $record, $table );

            if ( is_wp_error( $product_id ) ) {
                $stats['errors']++;
            } elseif ( $tipo === 'I' ) {
                $stats['inserted']++;
            } elseif ( $tipo === 'A' ) {
                $stats['deleted']++;
            } else {
                $stats['updated']++;
            }
        }

        update_option( 'farmadati_last_sync_' . $table, current_time( 'mysql' ) );

        return $stats;
    }

    /**
     * Hook del cron giornaliero: sincronizza tutte le tabelle abilitate.
     */
    public static function run_daily_sync() {
        $options = get_option( 'farmadati_wc_options', [] );
        $tables  = $options['sync_tables'] ?? [ 'TE001' ];

        $log = [];

        foreach ( $tables as $table ) {
            $full_imported = get_option( 'farmadati_last_full_import_' . $table, '' );

            if ( empty( $full_imported ) ) {
                // Prima volta: import completo
                $result = self::full_import( $table );
            } else {
                // Import differenziale
                $result = self::incremental_sync( $table );
            }

            $log[ $table ] = is_wp_error( $result ) ? $result->get_error_message() : $result;
        }

        update_option( 'farmadati_last_cron_log', $log );
        update_option( 'farmadati_last_cron_run', current_time( 'mysql' ) );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private static function get_or_create_tax_class( $iva_percent ) {
        // WooCommerce ha di default: '' = Standard, 'reduced-rate', 'zero-rate'
        // Per IVA italiana: 22% = standard, 10% = ridotta, 4% = super ridotta, 0% = esente
        $map = [
            22 => '',
            10 => 'reduced-rate',
            4  => 'super-reduced-rate',
            0  => 'zero-rate',
        ];

        if ( isset( $map[ $iva_percent ] ) ) {
            return $map[ $iva_percent ];
        }

        // Per aliquote non standard, usa il rate standard
        return '';
    }

    private static function assign_category( $product_id, $table ) {
        $category_map = [
            'TE001' => 'Parafarmaci e Dispositivi Medici',
            'TE002' => 'Medicinali SOP/OTC',
            'TE006' => 'Omeopatici',
            'TE011' => 'Veterinari',
            'TE015' => 'Veterinari SOP',
        ];

        $category_name = $category_map[ $table ] ?? 'Farmadati';

        $term = get_term_by( 'name', $category_name, 'product_cat' );
        if ( ! $term ) {
            $result = wp_insert_term( $category_name, 'product_cat' );
            if ( is_wp_error( $result ) ) {
                return;
            }
            $term_id = $result['term_id'];
        } else {
            $term_id = $term->term_id;
        }

        $existing_cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! in_array( $term_id, $existing_cats, true ) ) {
            $existing_cats[] = $term_id;
            wp_set_post_terms( $product_id, $existing_cats, 'product_cat' );
        }
    }
}
