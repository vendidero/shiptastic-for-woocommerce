window.shiptastic = window.shiptastic || {};
window.shiptastic.admin = window.shiptastic.admin || {};

( function( $, shipments ) {
    /**
     * Warehouse edit page: storage location settings.
     */
    shipments.admin.warehouse = {
        params: {},
        previewTimeout: null,
        previewRequest: null,
        keyCounter: 0,

        init: function() {
            var self = shipments.admin.warehouse;

            self.params = window.wc_shiptastic_admin_warehouse_params || {};

            $( document )
                .on( 'change', '#warehouse_location_levels_wrapper select, #warehouse_location_levels_wrapper input', self.onChangeLevels )
                .on( 'input', '#warehouse_location_levels_wrapper input[type=text]', self.onChangeLevels )
                .on( 'click', '#warehouse_location_levels_wrapper a.add, #warehouse_location_levels_wrapper a.remove_rows', function() {
                    // Wait for the row to be added/removed by the settings scripts.
                    window.setTimeout( self.onChangeLevels, 50 );
                } )
                .on( 'sortupdate', '#warehouse_location_levels_wrapper tbody', self.onChangeLevels )
                .on( 'input change', '#warehouse_location_separator, #warehouse_location_test_codes, .wc-stc-location-list-values input, #warehouse_location_overrides_wrapper input', self.schedulePreview )
                .on( 'sortupdate', '.wc-stc-location-list-values tbody, #warehouse_location_overrides_wrapper tbody', self.schedulePreview )
                .on( 'click', '.wc-stc-location-list-values a.add, .wc-stc-location-list-values a.remove_rows, #warehouse_location_overrides_wrapper a.add, #warehouse_location_overrides_wrapper a.remove_rows', function() {
                    window.setTimeout( self.schedulePreview, 50 );
                } )
                .on( 'submit', 'form', self.onSubmit );

            self.refreshLevels();
        },

        onChangeLevels: function() {
            var self = shipments.admin.warehouse;

            self.refreshLevels();
            self.schedulePreview();
        },

        getLevelRows: function() {
            return $( '#warehouse_location_levels_wrapper tbody tr.item' ).filter( function() {
                return ! $( this ).hasClass( 'ui-sortable-helper' ) && ! $( this ).hasClass( 'ui-sortable-placeholder' );
            } );
        },

        setToggle: function( $cell, checked ) {
            var $checkbox = $cell.find( 'input[type=checkbox]' ),
                $toggle   = $cell.find( 'span.woocommerce-shiptastic-input-toggle' );

            $checkbox.prop( 'checked', checked );
            $toggle.toggleClass( 'woocommerce-input-toggle--enabled', checked ).toggleClass( 'woocommerce-input-toggle--disabled', ! checked );
        },

        /**
         * - Each level row gets a key used to assign the values of list levels
         * - Every list level gets its own value table, titled by the level name, in level order
         * - Snake refers to the previous level and is not available for the first level
         */
        refreshLevels: function() {
            var self      = shipments.admin.warehouse,
                $rows     = self.getLevelRows(),
                $template = $( 'tr.wc-stc-location-list-template' ),
                $insertAt = $template,
                listKeys  = [];

            $rows.each( function( index ) {
                var $row       = $( this ),
                    $key       = $row.find( 'input.wc-stc-location-level-key' ),
                    $snakeCell = $row.find( 'td.wc-stc-location-level-snake' ),
                    type       = $row.find( 'select[name$="[type]"]' ).val(),
                    name       = $.trim( $row.find( 'input[name$="[name]"]' ).val() ),
                    $list;

                if ( ! $key.val() ) {
                    $key.val( 'new-' + ( ++self.keyCounter ) + '-' + Date.now() );
                }

                $snakeCell.find( 'a.woocommerce-shiptastic-input-toggle-trigger' ).css( 'visibility', 0 === index ? 'hidden' : 'visible' );

                if ( 0 === index ) {
                    self.setToggle( $snakeCell, false );
                }

                if ( 'list' !== type ) {
                    return;
                }

                listKeys.push( $key.val() );

                $list = $( 'tr.wc-stc-location-list[data-level-key="' + $key.val() + '"]' );

                if ( 0 === $list.length ) {
                    $list = self.createList( $template, $key.val() );
                }

                if ( ! name ) {
                    name = ( self.params.i18n_level_number || 'Level %d' ).replace( '%d', index + 1 );
                }

                $list.find( '.wc-stc-location-list-title' ).text( $list.find( '.wc-stc-location-list-title' ).data( 'title' ).replace( '%s', name ) );

                // Keep tables in level order (before the template).
                $list.insertBefore( $template );
            } );

            // Remove tables of levels which are no longer lists (or were removed).
            $( 'tr.wc-stc-location-list' ).not( '.wc-stc-location-list-template' ).each( function() {
                if ( $.inArray( String( $( this ).data( 'level-key' ) ), listKeys ) === -1 ) {
                    $( this ).remove();
                }
            } );
        },

        createList: function( $template, key ) {
            var self  = shipments.admin.warehouse,
                $list = $template.clone();

            $list.removeClass( 'wc-stc-location-list-template' ).attr( 'data-level-key', key ).data( 'level-key', key ).show();

            $list.find( ':input[name]' ).each( function() {
                $( this ).attr( 'name', $( this ).attr( 'name' ).replace( '__KEY__', key ) );
            } );

            self.initInputTable( $list.find( 'table.wc_input_table' ) );
            $( document.body ).trigger( 'init_tooltips' );

            return $list;
        },

        /**
         * Woo binds sorting, row selection and row removal to input tables existing on page load only.
         */
        initInputTable: function( $table ) {
            $table.find( 'tbody' ).sortable( {
                items: 'tr',
                cursor: 'move',
                axis: 'y',
                scrollSensitivity: 40,
                forcePlaceholderSize: true,
                helper: 'clone',
                opacity: 0.65,
                placeholder: 'wc-metabox-sortable-placeholder',
                start: function( event, ui ) {
                    ui.item.css( 'background-color', '#f6f6f6' );
                },
                stop: function( event, ui ) {
                    ui.item.removeAttr( 'style' );
                }
            } );

            $table.on( 'focus click', '> tbody input', function() {
                $table.find( 'tbody tr' ).removeClass( 'current' );
                $( this ).closest( 'tr' ).addClass( 'current' );
            } );

            $table.find( '.remove_rows' ).on( 'click', function() {
                $table.find( 'tbody tr.current' ).remove();
                return false;
            } );
        },

        schedulePreview: function() {
            var self = shipments.admin.warehouse;

            window.clearTimeout( self.previewTimeout );
            self.previewTimeout = window.setTimeout( self.refreshPreview, 400 );
        },

        /**
         * Renders the preview based on the current (unsaved) settings.
         */
        refreshPreview: function() {
            var self     = shipments.admin.warehouse,
                $preview = $( '#warehouse_location_preview' ),
                $form    = $preview.closest( 'form' ),
                data     = [];

            if ( 0 === $preview.length || ! self.params.ajax_url ) {
                return;
            }

            if ( self.previewRequest ) {
                self.previewRequest.abort();
            }

            var addInput = function( $input, name ) {
                if ( $input.is( ':checkbox' ) && ! $input.is( ':checked' ) ) {
                    return;
                }

                data.push( { name: name || $input.attr( 'name' ), value: $input.val() } );
            };

            data.push( { name: 'warehouse_location_separator', value: $( '#warehouse_location_separator' ).val() } );

            // Level and exception rows are indexed by their position (same as on submit).
            $form.find( '#warehouse_location_levels_wrapper tbody, #warehouse_location_overrides_wrapper tbody' ).each( function() {
                $( this ).find( 'tr.item' ).not( '.ui-sortable-helper, .ui-sortable-placeholder' ).each( function( index ) {
                    $( this ).find( ':input[name]' ).each( function() {
                        addInput( $( this ), $( this ).attr( 'name' ).replace( /^([^\[]+)\[[^\]]*\]/, '$1[' + index + ']' ) );
                    } );
                } );
            } );

            $form.find( '.wc-stc-location-list' ).not( '.wc-stc-location-list-template' ).find( 'table.wc_input_table > tbody > tr.item' ).not( '.ui-sortable-helper, .ui-sortable-placeholder' ).find( ':input[name]' ).each( function() {
                addInput( $( this ) );
            } );

            data.push( { name: 'action', value: 'woocommerce_stc_preview_warehouse_locations' } );
            data.push( { name: 'security', value: self.params.preview_nonce } );
            data.push( { name: 'warehouse_id', value: $( '#warehouse_location_test_codes' ).data( 'warehouse-id' ) } );
            data.push( { name: 'test_codes', value: $( '#warehouse_location_test_codes' ).val() } );

            $preview.addClass( 'is-loading' );

            self.previewRequest = $.ajax( {
                type: 'POST',
                url: self.params.ajax_url,
                data: data,
                success: function( response ) {
                    if ( response.success && response.data && response.data.html !== undefined ) {
                        $preview.html( response.data.html );
                    }
                },
                complete: function() {
                    $preview.removeClass( 'is-loading' );
                    self.previewRequest = null;
                }
            } );
        },

        /**
         * Input table rows are indexed by position on submit as row indices may collide after removing/sorting rows.
         * List values use [] names and are skipped.
         */
        onSubmit: function() {
            $( this ).find( '.wc_input_table' ).not( '.wc-stc-location-list-values' ).find( 'tbody' ).each( function() {
                $( this ).find( 'tr.item' ).each( function( index ) {
                    $( this ).find( ':input[name]' ).each( function() {
                        $( this ).attr( 'name', $( this ).attr( 'name' ).replace( /^([^\[]+)\[[^\]]*\]/, '$1[' + index + ']' ) );
                    } );
                } );
            } );

            // Do not submit the list template.
            $( this ).find( 'tr.wc-stc-location-list-template :input[name]' ).removeAttr( 'name' );
        }
    };

    $( document ).ready( function() {
        shipments.admin.warehouse.init();
    } );

} )( jQuery, window.shiptastic );
