/* global farmadatiAjax, jQuery */
(function ($) {
    'use strict';

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Test connessione
    // -------------------------------------------------------------------------
    $(document).on('click', '#farmadati-test-btn', function () {
        var $btn    = $(this);
        var $result = $('#farmadati-test-result');

        $btn.prop('disabled', true).text('Test in corso…');
        $result.html('').removeClass('notice-success notice-error').hide();

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_test_connection',
            nonce:  farmadatiAjax.nonce
        })
        .done(function (res) {
            if (res.success) {
                $result.addClass('notice notice-success').html('<p>' + res.data + '</p>').show();
            } else {
                $result.addClass('notice notice-error').html('<p>' + res.data + '</p>').show();
            }
        })
        .fail(function () {
            $result.addClass('notice notice-error').html('<p>Errore di comunicazione con il server.</p>').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('Testa connessione');
        });
    });

    // -------------------------------------------------------------------------
    // Ricerca prodotti
    // -------------------------------------------------------------------------
    var currentPage = 1;

    var resultFields = [];

    function buildHeaders(records) {
        if (!records || records.length === 0) { return ''; }
        var keys = Object.keys(records[0]).filter(function (k) {
            return k.indexOf('_wc_') !== 0;
        });
        resultFields = keys;
        var html = '<tr>';
        $.each(keys, function (i, k) { html += '<th>' + escHtml(k) + '</th>'; });
        html += '<th>Azione</th></tr>';
        return html;
    }

    function buildRows(records) {
        if (!records || records.length === 0) {
            return '<tr><td colspan="8">Nessun risultato trovato.</td></tr>';
        }

        var html = '';
        $.each(records, function (i, r) {
            var btnLabel = r._wc_id ? 'Aggiorna' : 'Importa';
            var editLink = r._wc_id
                ? ' <a href="' + escHtml(r._wc_edit) + '" class="button button-small" target="_blank">Modifica</a>'
                : '';

            html += '<tr>';
            $.each(resultFields, function (j, k) {
                var val = r[k] !== undefined ? r[k] : '';
                if (k === 'FDI_0245') {
                    val = val === 'S'
                        ? '<span class="farmadati-badge farmadati-badge--on">Sì</span>'
                        : '<span class="farmadati-badge farmadati-badge--off">No</span>';
                    html += '<td>' + val + '</td>';
                } else {
                    html += '<td>' + escHtml(String(val)) + '</td>';
                }
            });
            html += '<td>';
            html += '<button class="button button-primary fd-import-btn" data-record=\'' + JSON.stringify(r) + '\'>' + btnLabel + '</button>';
            html += editLink;
            html += '</td>';
            html += '</tr>';
        });
        return html;
    }

    function doSearch(page) {
        var table    = $('#fd-table').val();
        var fieldKey = $('#fd-search-type').val();
        var query    = $('#fd-search-query').val();
        var $spinner = $('#fd-search-spinner');
        var $wrap    = $('#fd-results-wrap');

        currentPage = parseInt(page, 10) || 1;

        $spinner.addClass('is-active');
        $('#fd-results-body').html('');
        $('#fd-pagination').html('');

        $.post(farmadatiAjax.ajaxurl, {
            action:    'farmadati_search',
            nonce:     farmadatiAjax.nonce,
            table:     table,
            field_key: fieldKey,
            query:     query,
            page:      currentPage
        })
        .done(function (res) {
            if (!res.success) {
                $('#fd-results-notice').html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
                $wrap.show();
                return;
            }

            var data = res.data;
            $('#fd-results-count').text(data.total || data.records.length);
            $('#fd-results-notice').html('');
            $('#fd-results-head').html(buildHeaders(data.records));
            $('#fd-results-body').html(buildRows(data.records));
            $wrap.show();

            // Paginazione
            if (data.pages > 1) {
                var pagHtml = '';
                if (currentPage > 1) {
                    pagHtml += '<button class="button fd-page-btn" data-page="' + (currentPage - 1) + '">&laquo; Prec</button> ';
                }
                pagHtml += '<span>Pagina ' + currentPage + ' / ' + data.pages + '</span>';
                if (currentPage < data.pages) {
                    pagHtml += ' <button class="button fd-page-btn" data-page="' + (currentPage + 1) + '">Succ &raquo;</button>';
                }
                $('#fd-pagination').html(pagHtml);
            }
        })
        .fail(function () {
            $('#fd-results-notice').html('<div class="notice notice-error"><p>Errore di comunicazione con il server.</p></div>');
            $wrap.show();
        })
        .always(function () {
            $spinner.removeClass('is-active');
        });
    }

    var schemaFields = [];

    function pickCodeField(fields) {
        // Preferisce il campo il cui key o descrizione contiene "cod"
        var preferred = null;
        $.each(fields, function (i, f) {
            var haystack = (f.key + ' ' + f.description).toLowerCase();
            if (!preferred && haystack.indexOf('cod') !== -1) {
                preferred = f;
                return false;
            }
        });
        return preferred || fields[0] || null;
    }

    function loadSchema(table) {
        $('#fd-field-hint').text('Caricamento campi…');
        $('#fd-search-btn').prop('disabled', true);

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_get_schema',
            nonce:  farmadatiAjax.nonce,
            table:  table
        })
        .done(function (res) {
            if (!res.success || !res.data.length) {
                $('#fd-field-hint').text('Schema non disponibile.');
                return;
            }
            schemaFields = res.data;
            var code = pickCodeField(res.data);
            var opts = '';
            $.each(res.data, function (i, f) {
                var sel = (code && f.key === code.key) ? ' selected' : '';
                opts += '<option value="' + escHtml(f.key) + '"' + sel + '>' + escHtml(f.key + ' – ' + f.description) + '</option>';
            });
            $('#fd-search-type').html(opts);
            if (code) {
                $('#fd-field-hint').text('Campo: ' + code.key + ' – ' + code.description);
            }
        })
        .fail(function () {
            $('#fd-field-hint').text('Impossibile caricare lo schema.');
        })
        .always(function () {
            $('#fd-search-btn').prop('disabled', false);
        });
    }

    // Carica schema alla selezione di una tabella
    $(document).on('change', '#fd-table', function () {
        loadSchema($(this).val());
    });

    // Carica schema per la tabella default al caricamento della pagina
    if ($('#fd-table').length) {
        loadSchema($('#fd-table').val());
    }

    $(document).on('click', '#fd-search-btn', function () {
        doSearch(1);
    });

    $(document).on('keypress', '#fd-search-query', function (e) {
        if (e.which === 13) { doSearch(1); }
    });

    $(document).on('click', '.fd-page-btn', function () {
        doSearch($(this).data('page'));
    });

    // -------------------------------------------------------------------------
    // Importazione singolo prodotto
    // -------------------------------------------------------------------------
    $(document).on('click', '.fd-import-btn', function () {
        var $btn   = $(this);
        var record = $btn.data('record');
        var table  = $('#fd-table').val() || 'TE001';

        $btn.prop('disabled', true).text('Importazione…');

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_import_product',
            nonce:  farmadatiAjax.nonce,
            record: JSON.stringify(record),
            table:  table
        })
        .done(function (res) {
            if (res.success) {
                $btn.text('Importato ✓').addClass('button-disabled');
                if (res.data.edit_url && !$btn.next('.fd-edit-link').length) {
                    $btn.after(' <a href="' + escHtml(res.data.edit_url) + '" class="button button-small fd-edit-link" target="_blank">Modifica</a>');
                }
            } else {
                alert(res.data);
                $btn.prop('disabled', false).text('Importa');
            }
        })
        .fail(function () {
            alert('Errore di comunicazione con il server.');
            $btn.prop('disabled', false).text('Importa');
        });
    });

    // -------------------------------------------------------------------------
    // Sincronizzazione manuale
    // -------------------------------------------------------------------------
    $(document).on('click', '#fd-sync-btn', function () {
        var $btn     = $(this);
        var $result  = $('#fd-sync-result');
        var $spinner = $('#fd-sync-spinner');
        var syncType = $('input[name="fd-sync-type"]:checked').val() || 'incremental';
        var table    = $('#fd-sync-table').val() || '';

        $btn.prop('disabled', true).text('Sincronizzazione…');
        $spinner.addClass('is-active');
        $result.html('<p>Sincronizzazione in corso, attendere…</p>');

        $.post(farmadatiAjax.ajaxurl, {
            action:    'farmadati_run_sync',
            nonce:     farmadatiAjax.nonce,
            sync_type: syncType,
            table:     table
        })
        .done(function (res) {
            if (res.success) {
                var html = '<table class="widefat fixed"><thead><tr><th>Tabella</th><th>Risultato</th></tr></thead><tbody>';
                $.each(res.data, function (t, result) {
                    var cell = (typeof result === 'object') ? JSON.stringify(result) : escHtml(String(result));
                    html += '<tr><td><strong>' + escHtml(t) + '</strong></td><td>' + cell + '</td></tr>';
                });
                html += '</tbody></table>';
                $result.html(html);
            } else {
                $result.html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error"><p>Errore di comunicazione con il server.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Avvia sincronizzazione');
            $spinner.removeClass('is-active');
        });
    });


    // -------------------------------------------------------------------------
    // CSV Sync – Stadio 1
    // -------------------------------------------------------------------------
    $(document).on('click', '#fd-csv-sync-btn', function () {
        var $btn     = $(this);
        var $spinner = $('#fd-csv-sync-spinner');
        var $result  = $('#fd-csv-sync-result');

        $btn.prop('disabled', true).text('Sincronizzazione…');
        $spinner.addClass('is-active');
        $result.html('<p>Lettura CSV e aggiornamento prodotti in corso…</p>');

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_csv_sync',
            nonce:  farmadatiAjax.nonce
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data;
                $result.html(
                    '<div class="notice notice-success" style="margin:0;"><p>' +
                    '<strong>Sync completata!</strong> ' +
                    'Nuovi: <strong>' + (d.new || 0) + '</strong> &nbsp;| ' +
                    'Aggiornati: <strong>' + (d.updated || 0) + '</strong> &nbsp;| ' +
                    'Errori: <strong>' + (d.errors || 0) + '</strong> ' +
                    '(su ' + (d.total || 0) + ' totali)' +
                    '</p></div>'
                );
            } else {
                $result.html('<div class="notice notice-error" style="margin:0;"><p>' + escHtml(res.data) + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error" style="margin:0;"><p>Errore di comunicazione con il server.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false).text('Avvia sync CSV');
            $spinner.removeClass('is-active');
        });
    });

    // -------------------------------------------------------------------------
    // CSV Enrich – Stadio 2 (batch AJAX)
    // -------------------------------------------------------------------------
    var enrichRunning   = false;
    var enrichTotal     = 0;
    var enrichDone      = 0;
    var enrichEmptyRuns = 0;   // guard contro loop infiniti

    function runEnrichBatch() {
        if (!enrichRunning) { return; }

        $.post(farmadatiAjax.ajaxurl, {
            action: 'farmadati_csv_enrich',
            nonce:  farmadatiAjax.nonce
        })
        .done(function (res) {
            if (!enrichRunning) { return; }

            if (!res.success) {
                stopEnrich();
                $('#fd-enrich-result').html(
                    '<div class="notice notice-error" style="margin:0;"><p>' + escHtml(res.data) + '</p></div>'
                );
                return;
            }

            var d         = res.data;
            var batchDone = (d.enriched || 0) + (d.errors || 0);
            enrichDone   += batchDone;

            // Mostra errori del batch in un log espandibile
            if (d.log && d.log.length) {
                var $log = $('#fd-enrich-log');
                if (!$log.length) {
                    $('#fd-enrich-progress').after(
                        '<details id="fd-enrich-log-wrap" style="margin-top:8px;">' +
                        '<summary style="cursor:pointer;color:#50575e;">Log errori</summary>' +
                        '<ul id="fd-enrich-log" style="font-size:12px;max-height:150px;overflow-y:auto;margin:4px 0;"></ul>' +
                        '</details>'
                    );
                    $log = $('#fd-enrich-log');
                }
                $.each(d.log, function (i, msg) {
                    $log.append('<li>' + escHtml(msg) + '</li>');
                });
            }

            var remaining = parseInt(d.remaining, 10) || 0;

            // Ricalcola il totale ad ogni batch (remaining + processati finora)
            enrichTotal = enrichDone + remaining;

            var pct = enrichTotal > 0
                ? Math.min(100, Math.round(enrichDone / enrichTotal * 100))
                : 100;

            $('#fd-enrich-bar').css('width', pct + '%');
            $('#fd-enrich-status').text(
                'Arricchiti: ' + enrichDone + ' / ' + enrichTotal +
                '  —  Rimanenti: ' + remaining
            );

            if (remaining === 0) {
                stopEnrich();
                $('#fd-enrich-result').html(
                    '<div class="notice notice-success" style="margin:0;"><p>' +
                    '<strong>Arricchimento completato!</strong> ' +
                    'Prodotti aggiornati: <strong>' + enrichDone + '</strong>' +
                    '</p></div>'
                );
                return;
            }

            // Guard: se 3 batch consecutivi non processano nulla → fermati
            if (batchDone === 0) {
                enrichEmptyRuns++;
                if (enrichEmptyRuns >= 3) {
                    stopEnrich();
                    $('#fd-enrich-result').html(
                        '<div class="notice notice-warning" style="margin:0;"><p>' +
                        'Arricchimento interrotto: nessun prodotto processato negli ultimi batch. ' +
                        'Rimanenti: ' + remaining + '. Riprova più tardi.' +
                        '</p></div>'
                    );
                    return;
                }
            } else {
                enrichEmptyRuns = 0;
            }

            setTimeout(runEnrichBatch, 300);
        })
        .fail(function () {
            stopEnrich();
            $('#fd-enrich-result').html(
                '<div class="notice notice-error" style="margin:0;"><p>Errore di comunicazione con il server.</p></div>'
            );
        });
    }

    function stopEnrich() {
        enrichRunning = false;
        $('#fd-enrich-btn').prop('disabled', false).show();
        $('#fd-enrich-stop-btn').hide();
        $('#fd-enrich-spinner').removeClass('is-active');
    }

    $(document).on('click', '#fd-enrich-btn', function () {
        enrichRunning   = true;
        enrichTotal     = 0;
        enrichDone      = 0;
        enrichEmptyRuns = 0;

        $(this).prop('disabled', true).hide();
        $('#fd-enrich-stop-btn').show();
        $('#fd-enrich-spinner').addClass('is-active');
        $('#fd-enrich-progress').show();
        $('#fd-enrich-result').html('');
        $('#fd-enrich-bar').css('width', '0%');
        $('#fd-enrich-status').text('Avvio…');

        runEnrichBatch();
    });

    $(document).on('click', '#fd-enrich-stop-btn', function () {
        enrichRunning = false;
        stopEnrich();
        $('#fd-enrich-result').html(
            '<div class="notice notice-warning" style="margin:0;"><p>Arricchimento interrotto. Puoi riprendere avviando di nuovo.</p></div>'
        );
    });

}(jQuery));
