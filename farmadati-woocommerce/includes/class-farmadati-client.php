<?php
/**
 * Client SOAP per i Web Services Farmadati Italia BDF2.0
 *
 * Supporta Method 1 (Interrogazione Remota) e Method 2 (Download Tabelle).
 */
class Farmadati_Client {

    const ENDPOINT_M1 = 'http://webservices-farmadati.dyndns.ws/WS2/FarmadatiItaliaWebServicesM1.svc';
    const ENDPOINT_M2 = 'http://webservices-farmadati.dyndns.ws/WS2/FarmadatiItaliaWebServicesM2.svc';

    const WSDL_M1 = 'http://webservices-farmadati.dyndns.ws/WS2/FarmadatiItaliaWebServicesM1.svc?singleWsdl';
    const WSDL_M2 = 'http://webservices-farmadati.dyndns.ws/WS2/FarmadatiItaliaWebServicesM2.svc?singleWsdl';

    private $username;
    private $password;
    private $client_m1 = null;
    private $client_m2 = null;

    public function __construct( $username, $password ) {
        $this->username = $username;
        $this->password = $password;
    }

    private function get_soap_options() {
        return [
            'trace'              => false,
            'exceptions'         => true,
            'connection_timeout' => 30,
            'default_socket_timeout' => 120,
            'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
            'cache_wsdl'         => WSDL_CACHE_NONE,
        ];
    }

    /**
     * Restituisce il percorso del file WSDL: prima cerca la copia locale nel plugin,
     * poi prova il download remoto come fallback.
     */
    private function fetch_wsdl( $wsdl_url, $cache_key ) {
        // Usa il file WSDL incluso nel plugin (più affidabile)
        $local = FARMADATI_WC_PLUGIN_DIR . 'wsdl/' . strtoupper( $cache_key ) . '.wsdl';
        if ( file_exists( $local ) ) {
            return $local;
        }

        // Fallback: download remoto
        $upload_dir = wp_upload_dir();
        $cache_file = trailingslashit( $upload_dir['basedir'] ) . 'farmadati-wsdl-' . $cache_key . '.xml';

        // Invalida la cache se il file salvato non è XML valido
        if ( file_exists( $cache_file ) ) {
            $cached = file_get_contents( $cache_file, false, null, 0, 100 );
            $is_xml = $cached && ( strpos( $cached, '<?xml' ) === 0 || strpos( $cached, '<wsdl' ) === 0 || strpos( $cached, '<definitions' ) !== false );
            if ( $is_xml && ( time() - filemtime( $cache_file ) ) < DAY_IN_SECONDS ) {
                return $cache_file;
            }
            // Cache non valida: cancella
            @unlink( $cache_file );
        }

        // Prova prima ?singleWsdl, poi ?wsdl
        $urls_to_try = [ $wsdl_url ];
        if ( strpos( $wsdl_url, 'singleWsdl' ) !== false ) {
            $urls_to_try[] = str_replace( 'singleWsdl', 'wsdl', $wsdl_url );
        }

        foreach ( $urls_to_try as $url ) {
            foreach ( [ true, false ] as $sslverify ) {
                $response = wp_remote_get( $url, [
                    'timeout'   => 30,
                    'sslverify' => $sslverify,
                ] );

                if ( is_wp_error( $response ) ) {
                    continue;
                }

                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );

                if ( $code !== 200 || empty( $body ) ) {
                    continue;
                }

                // Verifica che sia XML
                $start = ltrim( substr( $body, 0, 200 ) );
                if ( strpos( $start, '<?xml' ) !== 0 && strpos( $start, '<wsdl' ) !== 0 && strpos( $start, '<definitions' ) === false ) {
                    // Non è XML — salva il debug e continua
                    update_option( 'farmadati_wsdl_debug', substr( $body, 0, 500 ) );
                    continue;
                }

                file_put_contents( $cache_file, $body );
                return $cache_file;
            }
        }

        $debug = get_option( 'farmadati_wsdl_debug', '' );
        $hint  = $debug ? ' Risposta server: ' . esc_html( substr( $debug, 0, 200 ) ) : '';
        return new WP_Error( 'wsdl_unavailable',
            'Impossibile scaricare il WSDL da ' . $wsdl_url . '.' . $hint
        );
    }

    private function client_m1() {
        if ( $this->client_m1 === null ) {
            $wsdl = $this->fetch_wsdl( self::WSDL_M1, 'm1' );
            if ( is_wp_error( $wsdl ) ) {
                throw new Exception( $wsdl->get_error_message() );
            }
            $this->client_m1 = new SoapClient( $wsdl, $this->get_soap_options() );
        }
        return $this->client_m1;
    }

    private function client_m2() {
        if ( $this->client_m2 === null ) {
            $wsdl = $this->fetch_wsdl( self::WSDL_M2, 'm2' );
            if ( is_wp_error( $wsdl ) ) {
                throw new Exception( $wsdl->get_error_message() );
            }
            $this->client_m2 = new SoapClient( $wsdl, $this->get_soap_options() );
        }
        return $this->client_m2;
    }

    /**
     * Restituisce l'elenco delle tabelle abilitate (Method 1).
     *
     * @return array|WP_Error
     */
    public function get_enabled_datasets_m1() {
        try {
            $result = $this->client_m1()->GetEnabledDataSet( [
                'Username' => $this->username,
                'Password' => $this->password,
            ] );

            $out = $result->GetEnabledDataSetResult ?? $result;
            if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore sconosciuto' );
            }

            $tables = [];
            $set = $out->SetDati ?? [];
            if ( ! is_array( $set ) ) {
                $set = [ $set ];
            }
            foreach ( $set as $item ) {
                $tables[] = [
                    'key'         => $item->Key,
                    'description' => $item->Description,
                    'url_news'    => $item->UrlNews ?? '',
                    'schema_code' => $item->SchemaCode ?? 0,
                ];
            }
            return $tables;
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Esegue una query su una tabella (Method 1 - ExecuteQuery).
     *
     * @param string $table          Codice tabella (es. "TE001")
     * @param array  $fields         Campi da estrarre, o ["ALL"]
     * @param array  $filters        Array di filtri: [['key'=>'FDI_0004','operator'=>'CONTIENE','value'=>'ibuprofene','or_group'=>0], ...]
     * @param array  $order          Array di ordinamento: [['key'=>'FDI_0004','order_type'=>'CRESCENTE'], ...]
     * @param int    $page           Numero pagina (da 1)
     * @param int    $page_size      Record per pagina (1-100)
     * @return array|WP_Error        ['records'=>[], 'total'=>int, 'pages'=>int]
     */
    public function execute_query( $table, $fields = [ 'ALL' ], $filters = [], $order = [], $page = 1, $page_size = 100 ) {
        try {
            $soap_filters = [];
            foreach ( $filters as $f ) {
                $soap_filters[] = [
                    'Key'      => $f['key'],
                    'Operator' => $f['operator'],
                    'OrGroup'  => (int) ( $f['or_group'] ?? 0 ),
                    'Value'    => $f['value'],
                ];
            }

            $soap_orders = [];
            foreach ( $order as $o ) {
                $soap_orders[] = [
                    'Key'       => $o['key'],
                    'OrderType' => $o['order_type'] ?? 'CRESCENTE',
                ];
            }

            $params = [
                'Username'         => $this->username,
                'Password'         => $this->password,
                'CodiceSetDati'    => $table,
                'CampiDaEstrarre'  => $fields,
                'Filtri'           => $soap_filters,
                'Ordinamento'      => $soap_orders,
                'Distinct'         => false,
                'Count'            => false,
                'PageN'            => (int) $page,
                'PagingN'          => (int) $page_size,
            ];

            $result = $this->client_m1()->ExecuteQuery( $params );
            $out = $result->ExecuteQueryResult ?? $result;

            if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore query' );
            }

            if ( ( $out->OutputValue ?? '' ) === 'EMPTY' || (int) ( $out->NumRecords ?? 0 ) === 0 ) {
                return [ 'records' => [], 'total' => 0, 'pages' => 0 ];
            }

            $records = $this->parse_xml_output( $out->OutputValue );
            $total   = (int) ( $out->NumRecords ?? count( $records ) );
            $per_pg  = (int) ( $out->RecordsPerPage ?? $page_size );
            $pages   = $per_pg > 0 ? (int) ceil( $total / $per_pg ) : 1;

            return [ 'records' => $records, 'total' => $total, 'pages' => $pages ];
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Conta i record di una tabella (Method 1).
     *
     * @param string $table
     * @param array  $filters
     * @return int|WP_Error
     */
    public function count_records( $table, $filters = [] ) {
        try {
            $soap_filters = [];
            foreach ( $filters as $f ) {
                $soap_filters[] = [
                    'Key'      => $f['key'],
                    'Operator' => $f['operator'],
                    'OrGroup'  => (int) ( $f['or_group'] ?? 0 ),
                    'Value'    => $f['value'],
                ];
            }

            $params = [
                'Username'        => $this->username,
                'Password'        => $this->password,
                'CodiceSetDati'   => $table,
                'CampiDaEstrarre' => [ 'ALL' ],
                'Filtri'          => $soap_filters,
                'Ordinamento'     => [],
                'Distinct'        => false,
                'Count'           => true,
                'PageN'           => 1,
                'PagingN'         => 1,
            ];

            $result = $this->client_m1()->ExecuteQuery( $params );
            $out = $result->ExecuteQueryResult ?? $result;

            if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore count' );
            }

            return (int) ( $out->NumRecords ?? 0 );
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Scarica una tabella completa (Method 2 - GetDataSet, modalità GETRECORDS).
     * Ritorna i record XML come array. Gestisce la paginazione automaticamente.
     *
     * @param string        $table
     * @param callable|null $progress_callback  function( $page, $total_pages )
     * @return array|WP_Error
     */
    public function get_dataset( $table, $progress_callback = null ) {
        try {
            $all_records = [];
            $page = 1;

            // Prima otteniamo il conteggio totale
            $count_result = $this->client_m2()->GetDataSet( [
                'Username'       => $this->username,
                'Password'       => $this->password,
                'CodiceSetDati'  => $table,
                'Modalita'       => 'COUNT',
                'PageN'          => '1',
            ] );
            $count_out = $count_result->GetDataSetResult ?? $count_result;

            if ( ( $count_out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_error', $count_out->DescEsito ?? 'Errore count' );
            }

            $total_records = (int) ( $count_out->OutputValue ?? 0 );
            // GetDataSet pages are ZIP files; each page contains up to ~5000 records
            $estimated_pages = max( 1, (int) ceil( $total_records / 5000 ) );

            while ( true ) {
                $result = $this->client_m2()->GetDataSet( [
                    'Username'      => $this->username,
                    'Password'      => $this->password,
                    'CodiceSetDati' => $table,
                    'Modalita'      => 'GETRECORDS',
                    'PageN'         => (string) $page,
                ] );

                $out = $result->GetDataSetResult ?? $result;

                if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                    return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore download' );
                }

                if ( ( $out->OutputValue ?? '' ) === 'EMPTY' ) {
                    break;
                }

                // ByteListFile è il file ZIP in base64
                $zip_data = base64_decode( $out->ByteListFile ?? '' );
                if ( empty( $zip_data ) ) {
                    break;
                }

                $records = $this->extract_xml_from_zip( $zip_data );
                if ( is_wp_error( $records ) ) {
                    return $records;
                }

                $all_records = array_merge( $all_records, $records );

                if ( $progress_callback ) {
                    call_user_func( $progress_callback, $page, $estimated_pages );
                }

                $page++;

                // Sicurezza: max 200 pagine
                if ( $page > 200 ) {
                    break;
                }
            }

            return $all_records;
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Scarica solo le variazioni dalla tabella (Method 2 - GetDataSetChanges).
     *
     * @param string $table
     * @param string $last_update  Data ultimo aggiornamento in formato dd/mm/yyyyHH:MM
     * @return array|WP_Error      Array di record con campi aggiuntivi DATA_VARIAZIONE e TIPO_VARIAZIONE
     */
    public function get_dataset_changes( $table, $last_update = '' ) {
        try {
            $all_records = [];
            $page = 1;

            while ( true ) {
                $params = [
                    'Username'      => $this->username,
                    'Password'      => $this->password,
                    'CodiceSetDati' => $table,
                    'PageN'         => (string) $page,
                ];

                if ( ! empty( $last_update ) ) {
                    $params['DataUltimoAggiornamento'] = $last_update;
                }

                $result = $this->client_m2()->GetDataSetChanges( $params );
                $out = $result->GetDataSetChangesResult ?? $result;

                if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                    return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore variazioni' );
                }

                if ( ( $out->OutputValue ?? '' ) === 'EMPTY' || (int) ( $out->NumRecords ?? 0 ) === 0 ) {
                    break;
                }

                $zip_data = base64_decode( $out->ByteListFile ?? '' );
                if ( empty( $zip_data ) ) {
                    break;
                }

                $records = $this->extract_xml_from_zip( $zip_data );
                if ( is_wp_error( $records ) ) {
                    return $records;
                }

                $all_records = array_merge( $all_records, $records );
                $page++;

                if ( $page > 200 ) {
                    break;
                }
            }

            return $all_records;
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Restituisce la struttura (campi) di una tabella (Method 1 - GetSchemaDataSet).
     *
     * @param string $table  Codice tabella (es. "TE001")
     * @return array|WP_Error  Array di ['key'=>..., 'description'=>..., 'type'=>...]
     */
    public function get_schema( $table ) {
        try {
            $result = $this->client_m1()->GetSchemaDataSet( [
                'Username'      => $this->username,
                'Password'      => $this->password,
                'CodiceSetDati' => $table,
            ] );

            $out = $result->GetSchemaDataSetResult ?? $result;
            if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_error', $out->DescEsito ?? 'Errore schema' );
            }

            $fields = [];
            $raw = $out->Fields->Field ?? [];
            if ( ! is_array( $raw ) ) {
                $raw = [ $raw ];
            }
            foreach ( $raw as $f ) {
                $fields[] = [
                    'key'         => $f->Key ?? '',
                    'description' => $f->Description ?? $f->Key ?? '',
                    'type'        => $f->Type ?? '',
                ];
            }
            return $fields;
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Verifica le credenziali tentando GetEnabledDataSet (Method 2).
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        try {
            $result = $this->client_m1()->GetEnabledDataSet( [
                'Username' => $this->username,
                'Password' => $this->password,
            ] );
            $out = $result->GetEnabledDataSetResult ?? $result;
            if ( ( $out->CodEsito ?? '' ) !== 'OK' ) {
                return new WP_Error( 'farmadati_auth', $out->DescEsito ?? 'Credenziali non valide' );
            }
            return true;
        } catch ( Exception $e ) {
            return new WP_Error( 'farmadati_soap', $e->getMessage() );
        }
    }

    /**
     * Costruisce l'URL per recuperare un'immagine prodotto.
     *
     * @param string $filename  Valore del campo FDI_0843 (TE009) o FDI_9004 (TE006)
     * @param string $tipodoc   Tipo documento (TE009, TE004, ecc.)
     * @return string
     */
    public function get_image_url( $filename, $tipodoc = 'TE009' ) {
        return add_query_arg( [
            'accesskey' => rawurlencode( $this->password ),
            'tipodoc'   => $tipodoc,
            'nomefile'  => rawurlencode( $filename ),
        ], 'https://ws.farmadati.it/WS_DOC/GetDoc.aspx' );
    }

    // -------------------------------------------------------------------------
    // Metodi privati di parsing
    // -------------------------------------------------------------------------

    /**
     * Parsa l'OutputValue XML di ExecuteQuery in un array associativo.
     *
     * @param string $xml
     * @return array
     */
    private function parse_xml_output( $xml ) {
        if ( empty( $xml ) ) {
            return [];
        }

        $records = [];

        try {
            $dom = new DOMDocument();

            // Rimuove la dichiarazione XML iniziale se già presente nel payload
            // (TE009 restituisce XML completo; aggiungerne una seconda lo renderebbe invalido)
            $clean = preg_replace( '/^\s*<\?xml[^?]*\?>\s*/i', '', trim( $xml ) );
            $dom->loadXML(
                '<?xml version="1.0" encoding="utf-8"?><root>' . $clean . '</root>',
                LIBXML_NOERROR | LIBXML_NOWARNING
            );

            if ( ! $dom->documentElement ) {
                throw new Exception( 'XML non valido o vuoto' );
            }

            // Prima prova il tag standard <Record>
            $rows = $dom->getElementsByTagName( 'Record' );

            if ( $rows->length === 0 ) {
                // Fallback: trova qualsiasi elemento con figli FDI_*
                // (TE009 usa <Product>, altre tabelle potrebbero usare tag diversi)
                $xpath = new DOMXPath( $dom );
                $rows  = $xpath->query( '//*[*[starts-with(local-name(), "FDI_")]]' );
            }

            foreach ( $rows as $row ) {
                $record = [];
                foreach ( $row->childNodes as $field ) {
                    if ( $field->nodeType === XML_ELEMENT_NODE ) {
                        $record[ $field->nodeName ] = $field->nodeValue;
                    }
                }
                if ( ! empty( $record ) ) {
                    $records[] = $record;
                }
            }
        } catch ( Exception $e ) {
            // Parsing fallback: regex semplice
            preg_match_all( '/<([A-Z_0-9]+)>([^<]*)<\/\1>/', $xml, $matches, PREG_SET_ORDER );
            $record = [];
            foreach ( $matches as $m ) {
                $record[ $m[1] ] = $m[2];
            }
            if ( ! empty( $record ) ) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Estrae i record XML da un file ZIP in memoria.
     *
     * @param string $zip_data  Contenuto binario del file ZIP
     * @return array|WP_Error
     */
    private function extract_xml_from_zip( $zip_data ) {
        $tmp = tempnam( sys_get_temp_dir(), 'farmadati_' ) . '.zip';
        file_put_contents( $tmp, $zip_data );

        $zip = new ZipArchive();
        if ( $zip->open( $tmp ) !== true ) {
            unlink( $tmp );
            return new WP_Error( 'farmadati_zip', 'Impossibile aprire il file ZIP ricevuto da Farmadati.' );
        }

        $records = [];

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $xml_content = $zip->getFromIndex( $i );
            if ( $xml_content === false ) {
                continue;
            }
            $records = array_merge( $records, $this->parse_xml_file( $xml_content ) );
        }

        $zip->close();
        unlink( $tmp );

        return $records;
    }

    /**
     * Parsa un file XML Farmadati (formato tabella).
     *
     * @param string $xml_content
     * @return array
     */
    private function parse_xml_file( $xml_content ) {
        $records = [];

        try {
            $xml = simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NOERROR );
            if ( $xml === false ) {
                return [];
            }

            // La struttura XML di Farmadati ha i record come elementi figli del root
            foreach ( $xml->children() as $row ) {
                $record = [];
                foreach ( $row->children() as $field ) {
                    $record[ $field->getName() ] = (string) $field;
                }
                // Attributi come DATA_VARIAZIONE e TIPO_VARIAZIONE possono essere attributi XML
                foreach ( $row->attributes() as $attr_name => $attr_value ) {
                    $record[ $attr_name ] = (string) $attr_value;
                }
                if ( ! empty( $record ) ) {
                    $records[] = $record;
                }
            }
        } catch ( Exception $e ) {
            // Ignora errori di parsing
        }

        return $records;
    }
}
