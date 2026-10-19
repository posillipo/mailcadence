<?php
/**
 * Sincronizzazione CSV → WooCommerce con arricchimento Farmadati.
 *
 * Flusso a due stadi:
 *   1. sync_all_from_csv()  — legge scambiodati/products.csv, crea/aggiorna
 *      tutti i prodotti WooCommerce con dati gestionali (prezzi, stock, categorie).
 *      Veloce: solo operazioni DB, nessuna chiamata SOAP.
 *
 *   2. enrich_batch()  — per prodotti non ancora arricchiti, chiama Farmadati
 *      via SOAP per ottenere nome ufficiale, descrizione e immagine.
 *      Lento (una chiamata SOAP per prodotto); eseguito in batch via AJAX.
 */
class Farmadati_CSV_Sync {

    const CSV_SUBPATH  = 'scambiodati/products.csv';
    const ENRICH_BATCH = 5;

    public static function init() {
        add_action( 'farmadati_daily_sync', [ __CLASS__, 'run_daily' ] );
    }

    // =========================================================================
    // CSV – lettura e parsing
    // =========================================================================

    public static function get_csv_path() {
        return rtrim( ABSPATH, '/' ) . '/' . self::CSV_SUBPATH;
    }

    public static function csv_info() {
        $path = self::get_csv_path();
        if ( ! file_exists( $path ) ) {
            return null;
        }
        return [
            'path'     => $path,
            'size'     => filesize( $path ),
            'modified' => filemtime( $path ),
        ];
    }

    /**
     * Legge e parsa il CSV. Restituisce array di righe associative.
     *
     * @return array[]|WP_Error
     */
    public static function read_csv() {
        $path = self::get_csv_path();

        if ( ! file_exists( $path ) ) {
            return new WP_Error( 'csv_not_found',
                'File CSV non trovato: ' . esc_html( self::CSV_SUBPATH ) );
        }

        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', 'Impossibile aprire il file CSV.' );
        }

        // Salta BOM UTF-8 se presente
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }

        $header = null;
        $rows   = [];

        while ( ( $line = fgets( $handle ) ) !== false ) {
            $line = rtrim( $line, "\r\n" );
            if ( $line === '' ) {
                continue;
            }

            $fields = str_getcsv( $line, ';' );

            if ( $header === null ) {
                $header = $fields;
                continue;
            }

            // Padding se la riga ha meno campi dell'header
            $fields = array_pad( $fields, count( $header ), '' );
            $rows[] = array_combine( $header, array_slice( $fields, 0, count( $header ) ) );
        }

        fclose( $handle );

        return $rows;
    }

    // =========================================================================
    // Helper conversioni
    // =========================================================================

    private static function parse_price( $str ) {
        // "5,90" → 5.90
        return (float) str_replace( ',', '.', trim( (string) $str ) );
    }

    private static function iva_to_tax_class( $iva_code ) {
        $map = [
            '04' => 'super-reduced-rate',
            '4'  => 'super-reduced-rate',
            '10' => 'reduced-rate',
            '22' => '',
            '00' => 'zero-rate',
            '0'  => 'zero-rate',
        ];
        return $map[ trim( (string) $iva_code ) ] ?? '';
    }

    /**
     * Recupera o crea la categoria WooCommerce (gerarchica).
     *
     * @return int  term_id, 0 in caso di errore
     */
    private static function get_or_create_category( $parent_name, $child_name = '' ) {
        $parent_name = trim( (string) $parent_name );
        $child_name  = trim( (string) $child_name );

        if ( empty( $parent_name ) ) {
            return 0;
        }

        $parent_term = get_term_by( 'name', $parent_name, 'product_cat' );
        if ( ! $parent_term ) {
            $res = wp_insert_term( $parent_name, 'product_cat' );
            if ( is_wp_error( $res ) ) {
                return 0;
            }
            $parent_id = $res['term_id'];
        } else {
            $parent_id = $parent_term->term_id;
        }

        if ( empty( $child_name ) ) {
            return (int) $parent_id;
        }

        $child_term = get_term_by( 'name', $child_name, 'product_cat' );
        if ( ! $child_term ) {
            $res = wp_insert_term( $child_name, 'product_cat', [ 'parent' => $parent_id ] );
            if ( is_wp_error( $res ) ) {
                return (int) $parent_id;
            }
            return (int) $res['term_id'];
        }

        return (int) $child_term->term_id;
    }

    // =========================================================================
    // Stadio 1 – Sync CSV → WooCommerce
    // =========================================================================

    /**
     * Crea o aggiorna un prodotto WooCommerce con i dati di una riga CSV.
     * Non effettua chiamate SOAP.
     *
     * @param  array $row  Riga CSV associativa
     * @return int|WP_Error  product_id
     */
    public static function sync_product_from_csv( array $row ) {
        $aic = sanitize_text_field( trim( $row['CodiceAIC'] ?? '' ) );
        if ( empty( $aic ) ) {
            return new WP_Error( 'missing_aic', 'CodiceAIC mancante.' );
        }

        $product_id = wc_get_product_id_by_sku( $aic );

        if ( $product_id ) {
            $product = wc_get_product( $product_id );
        } else {
            $product = new WC_Product_Simple();
            $product->set_sku( $aic );
        }

        // Nome: usa il CSV solo se il prodotto non è ancora arricchito da Farmadati
        $nome = sanitize_text_field( $row['Nome'] ?? '' );
        if ( ! empty( $nome ) && ! $product->get_meta( '_farmadati_enriched' ) ) {
            $product->set_name( $nome );
        }

        // Prezzi
        $reg  = self::parse_price( $row['PrezzoIniziale'] ?? 0 );
        $sale = self::parse_price( $row['PrezzoEshop'] ?? 0 );

        if ( $reg > 0 && $sale > 0 && $sale < $reg ) {
            $product->set_regular_price( number_format( $reg, 2, '.', '' ) );
            $product->set_sale_price( number_format( $sale, 2, '.', '' ) );
        } elseif ( $reg > 0 ) {
            $product->set_regular_price( number_format( $reg, 2, '.', '' ) );
            $product->set_sale_price( '' );
        } elseif ( $sale > 0 ) {
            $product->set_regular_price( number_format( $sale, 2, '.', '' ) );
            $product->set_sale_price( '' );
        }

        // Stock
        $stock = (int) trim( $row['Giacenza'] ?? 0 );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $stock );
        $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );

        // Stato pubblicazione
        $pub = strtolower( trim( $row['Pubblicato'] ?? 'false' ) ) === 'true';
        $product->set_status( $pub ? 'publish' : 'draft' );
        if ( ! $pub ) {
            $product->set_catalog_visibility( 'hidden' );
        }

        // IVA
        $product->set_tax_class( self::iva_to_tax_class( $row['Iva'] ?? '' ) );

        // Peso: il CSV è in grammi, WooCommerce vuole kg
        $peso_g = self::parse_price( $row['Peso'] ?? 0 );
        if ( $peso_g > 0 ) {
            $product->set_weight( number_format( $peso_g / 1000, 3, '.', '' ) );
        }

        // Meta dati gestionali
        $product->update_meta_data( '_ean', sanitize_text_field( $row['CodiceEAN'] ?? '' ) );
        $product->update_meta_data( '_farmadati_csv_sync', current_time( 'mysql' ) );

        // Riferimenti Farmadati presenti nel CSV (potrebbero essere vuoti)
        $foto  = sanitize_text_field( $row['FotoFarmadati'] ?? '' );
        $scheda = sanitize_text_field( $row['SchedaDescrittiva'] ?? '' );
        if ( ! empty( $foto ) ) {
            $product->update_meta_data( '_farmadati_foto_csv', $foto );
        }
        if ( ! empty( $scheda ) ) {
            $product->update_meta_data( '_farmadati_scheda_csv', $scheda );
        }

        $product_id = $product->save();

        if ( ! $product_id ) {
            return new WP_Error( 'save_failed', "Salvataggio fallito per AIC: $aic" );
        }

        // Categoria gerarchica
        $cat_id = self::get_or_create_category(
            $row['Categoria'] ?? '',
            $row['SottoCategoria'] ?? ''
        );
        if ( $cat_id ) {
            wp_set_post_terms( $product_id, [ $cat_id ], 'product_cat' );
        }

        return $product_id;
    }

    /**
     * Legge tutto il CSV e aggiorna WooCommerce.
     *
     * @return array|WP_Error  ['new'=>int, 'updated'=>int, 'errors'=>int]
     */
    public static function sync_all_from_csv() {
        set_time_limit( 300 );

        $rows = self::read_csv();
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        $stats = [ 'new' => 0, 'updated' => 0, 'errors' => 0, 'total' => count( $rows ) ];

        foreach ( $rows as $row ) {
            $aic     = trim( $row['CodiceAIC'] ?? '' );
            $existed = $aic && wc_get_product_id_by_sku( $aic );
            $result  = self::sync_product_from_csv( $row );

            if ( is_wp_error( $result ) ) {
                $stats['errors']++;
            } elseif ( $existed ) {
                $stats['updated']++;
            } else {
                $stats['new']++;
            }
        }

        update_option( 'farmadati_csv_last_sync', current_time( 'mysql' ) );
        update_option( 'farmadati_csv_last_stats', $stats );

        return $stats;
    }

    // =========================================================================
    // Stadio 2 – Arricchimento Farmadati
    // =========================================================================

    /**
     * Cerca un prodotto per CodiceAIC nelle principali tabelle Farmadati.
     *
     * @param  string           $aic
     * @param  Farmadati_Client $client
     * @return array|WP_Error   ['name', 'description', 'image_filename', 'table', 'record']
     */
    public static function fetch_farmadati_data( $aic, $client ) {
        // Ordine di preferenza: TE001 copre la maggior parte, poi gli altri
        $tables = [ 'TE001', 'TE003', 'TE004', 'TE005', 'TE009', 'TE008', 'TE006' ];

        foreach ( $tables as $table ) {
            $result = $client->execute_query(
                $table,
                [ 'ALL' ],
                [ [ 'key' => 'FDI_0001', 'operator' => '=', 'value' => $aic, 'or_group' => 0 ] ],
                [],
                1,
                1
            );

            if ( is_wp_error( $result ) || empty( $result['records'] ) ) {
                continue;
            }

            $rec = $result['records'][0];

            // Nome: prova i campi più comuni in ordine di priorità
            $name = '';
            foreach ( [ 'FDI_0004', 'FDI_0003', 'FDI_0005', 'FDI_0006' ] as $fld ) {
                if ( ! empty( $rec[ $fld ] ) ) {
                    $name = $rec[ $fld ];
                    break;
                }
            }

            // Descrizione: cerca campi di testo lungo
            $description = '';
            foreach ( [ 'FDI_4887', 'FDI_1702', 'FDI_0007', 'FDI_0008' ] as $fld ) {
                if ( ! empty( $rec[ $fld ] ) && mb_strlen( $rec[ $fld ] ) > 20 ) {
                    $description = $rec[ $fld ];
                    break;
                }
            }

            // Immagine: cerca campi il cui valore è un nome file immagine
            $image_filename = '';
            foreach ( $rec as $val ) {
                if ( is_string( $val ) && preg_match( '/\.(jpe?g|png|gif|tiff?)$/i', trim( $val ) ) ) {
                    $image_filename = trim( $val );
                    break;
                }
            }

            return [
                'name'           => sanitize_text_field( $name ),
                'description'    => wp_kses_post( $description ),
                'image_filename' => $image_filename,
                'table'          => $table,
                'record'         => $rec,
            ];
        }

        return new WP_Error( 'fd_not_found', "AIC $aic non trovato in Farmadati." );
    }

    /**
     * Scarica e allega un'immagine da Farmadati come thumbnail del prodotto.
     *
     * @param  int              $product_id
     * @param  string           $filename
     * @param  string           $tipodoc     Codice tabella Farmadati (usato come tipo doc)
     * @param  Farmadati_Client $client
     * @return int|WP_Error     attachment_id
     */
    public static function attach_farmadati_image( $product_id, $filename, $tipodoc, $client ) {
        if ( empty( $filename ) ) {
            return new WP_Error( 'no_filename', 'Nome file immagine vuoto.' );
        }

        $url = $client->get_image_url( $filename, $tipodoc );

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];

        $attach_id = media_handle_sideload( $file_array, $product_id );
        @unlink( $tmp );

        if ( is_wp_error( $attach_id ) ) {
            return $attach_id;
        }

        set_post_thumbnail( $product_id, $attach_id );

        return $attach_id;
    }

    /**
     * Arricchisce un prodotto con i dati Farmadati (nome, desc, immagine).
     *
     * @param  int              $product_id
     * @param  string           $aic
     * @param  Farmadati_Client $client
     * @return true|WP_Error
     */
    public static function enrich_product( $product_id, $aic, $client ) {
        $fd = self::fetch_farmadati_data( $aic, $client );

        if ( is_wp_error( $fd ) ) {
            // Salva il messaggio di errore per non ritentare all'infinito
            update_post_meta( $product_id, '_farmadati_enrich_error', $fd->get_error_message() );
            return $fd;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'product_not_found', "Prodotto $product_id non trovato." );
        }

        if ( ! empty( $fd['name'] ) ) {
            $product->set_name( $fd['name'] );
        }
        if ( ! empty( $fd['description'] ) ) {
            $product->set_description( $fd['description'] );
        }

        $product->update_meta_data( '_farmadati_enriched', current_time( 'mysql' ) );
        $product->update_meta_data( '_farmadati_table', $fd['table'] );
        $product->save();

        // Immagine (solo se il prodotto non ce l'ha già)
        if ( ! has_post_thumbnail( $product_id ) ) {
            // 1. Immagine trovata nel record Farmadati
            if ( ! empty( $fd['image_filename'] ) ) {
                self::attach_farmadati_image( $product_id, $fd['image_filename'], $fd['table'], $client );
            } else {
                // 2. Immagine referenziata nel CSV (campo FotoFarmadati)
                $foto_csv = get_post_meta( $product_id, '_farmadati_foto_csv', true );
                if ( ! empty( $foto_csv ) ) {
                    self::attach_farmadati_image( $product_id, $foto_csv, $fd['table'], $client );
                }
            }
        }

        return true;
    }

    /**
     * Arricchisce un batch di prodotti non ancora processati.
     * Offset non necessario: la meta_query esclude già i prodotti arricchiti,
     * quindi la query restituisce sempre i primi N non ancora processati.
     *
     * @param  int $limit   Max self::ENRICH_BATCH
     * @return array|WP_Error  ['enriched', 'errors', 'remaining']
     */
    public static function enrich_batch( $limit = self::ENRICH_BATCH ) {
        $options  = get_option( 'farmadati_wc_options', [] );
        $username = $options['username'] ?? '';
        $password = $options['password'] ?? '';

        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'no_credentials', 'Credenziali Farmadati non configurate.' );
        }

        // Prodotti sincronizzati dal CSV ma non arricchiti (e senza errori precedenti)
        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => (int) min( $limit, self::ENRICH_BATCH ),
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_farmadati_csv_sync',
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => '_farmadati_enriched',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => '_farmadati_enrich_error',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $q      = new WP_Query( $args );
        $client = new Farmadati_Client( $username, $password );
        $stats  = [ 'enriched' => 0, 'errors' => 0, 'log' => [] ];

        foreach ( $q->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            $sku     = $product ? $product->get_sku() : '';

            if ( empty( $sku ) ) {
                update_post_meta( $product_id, '_farmadati_enrich_error', 'SKU mancante.' );
                $stats['errors']++;
                $stats['log'][] = "ID $product_id: SKU mancante";
                continue;
            }

            $res = self::enrich_product( $product_id, $sku, $client );
            if ( is_wp_error( $res ) ) {
                $stats['errors']++;
                $stats['log'][] = "AIC $sku: " . $res->get_error_message();
            } else {
                $stats['enriched']++;
            }
        }

        // Conta quanti restano
        $remaining_q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft' ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_farmadati_csv_sync',    'compare' => 'EXISTS' ],
                [ 'key' => '_farmadati_enriched',    'compare' => 'NOT EXISTS' ],
                [ 'key' => '_farmadati_enrich_error', 'compare' => 'NOT EXISTS' ],
            ],
        ] );
        $stats['remaining'] = (int) $remaining_q->found_posts;

        return $stats;
    }

    // =========================================================================
    // Cron giornaliero
    // =========================================================================

    public static function run_daily() {
        $result = self::sync_all_from_csv();
        $log    = is_wp_error( $result ) ? $result->get_error_message() : $result;
        update_option( 'farmadati_csv_cron_log', $log );
        update_option( 'farmadati_csv_cron_run', current_time( 'mysql' ) );
    }

    // =========================================================================
    // Statistiche
    // =========================================================================

    public static function get_stats() {
        $total = (int) wp_count_posts( 'product' )->publish
                 + (int) wp_count_posts( 'product' )->draft;

        $synced_q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_farmadati_csv_sync', 'compare' => 'EXISTS' ] ],
        ] );

        $enriched_q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_farmadati_enriched', 'compare' => 'EXISTS' ] ],
        ] );

        return [
            'total'    => $total,
            'synced'   => (int) $synced_q->found_posts,
            'enriched' => (int) $enriched_q->found_posts,
            'last_csv_sync' => get_option( 'farmadati_csv_last_sync', '' ),
            'last_stats'    => get_option( 'farmadati_csv_last_stats', [] ),
            'csv_exists'    => file_exists( self::get_csv_path() ),
            'csv_modified'  => file_exists( self::get_csv_path() )
                ? filemtime( self::get_csv_path() )
                : 0,
        ];
    }
}
