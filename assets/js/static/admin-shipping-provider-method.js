window.shiptastic = window.shiptastic || {};
window.shiptastic.admin = window.shiptastic.admin || {};

( function( $, shipments ) {

    /**
     * Core
     */
    shipments.admin.shipping_provider_method = {

        params: {},
        currentProvider: '',

        init: function () {
            var self = shipments.admin.shipping_provider_method;

            self.params = wc_shiptastic_admin_shipping_provider_method_params;

            $( document )
                .on( 'change', 'select[id$=shipping_provider]', self.showOrHideAll )
                .on( 'click', '.wc-stc-shipping-provider-method-tabs .nav-tab-wrapper a.nav-tab', self.onChangeTab )
                .on( 'change', '.override-checkbox :input', self.onChangeOverride )
                .on( 'change', '.wc-stc-shipping-provider-method-tab-content :input[id]', self.onChangeInput );

            $( document.body ).on( 'wc_backbone_modal_loaded', self.onShippingMethodOpen );

            if ( $( 'select[id$=shipping_provider]' ).length > 0 ) {
                $( 'select[id$=shipping_provider]' ).trigger( 'change' );
            }
        },

        onChangeInput: function() {
            var settings = shipments.admin.shipment_settings;

            settings.onChangeInput.call( $( this ) );
        },

        parseFieldId: function( fieldId ) {
            return fieldId.replace( '[', '_' ).replace( ']', '' );
        },

        onChangeOverride: function() {
            var self      = shipments.admin.shipping_provider_method,
                $checkbox = $( this ),
                isChecked = $checkbox.is( ':checked' ),
                $parent   = $checkbox.parents( '.wc-stc-shipping-provider-override-wrapper' );

            if ( isChecked ) {
                $parent.find( '.wc-stc-shipping-provider-override-inner-wrapper' ).addClass( 'has-override' );
            } else {
                $parent.find( '.wc-stc-shipping-provider-override-inner-wrapper' ).removeClass( 'has-override' );
            }
        },

        onChangeTab: function() {
            var self     = shipments.admin.shipping_provider_method,
                $navTab  = $( this ),
                $wrapper = $navTab.parents( 'form' ),
                tab      = $navTab.attr( 'href' ).replace( '#', '' ),
                $tab     = $wrapper.find( '.wc-stc-shipping-provider-method-tab-content[data-tab="' + tab + '"]' );

            $navTab.parents( '.wc-stc-shipping-provider-method-tabs' ).find( '.nav-tab-active' ).removeClass( 'nav-tab-active' );
            $wrapper.find( '.wc-stc-shipping-provider-method-tab-content' ).removeClass( 'tab-content-active' );

            if ( $tab.length > 0 ) {
                $navTab.addClass( 'nav-tab-active' );
                $tab.addClass( 'tab-content-active' );

                $tab.find( ':input:visible' ).trigger( 'change' );
            }

            return false;
        },

        /**
         * Is being provided as callback for shipments.admin.shipment_settings.getCleanInputId().
         *
         * @param $mainInput
         * @returns {*|boolean}
         */
        getCleanInputId: function( $mainInput ) {
            var self            = shipments.admin.shipping_provider_method,
                currentProvider = self.currentProvider,
                fieldId         = $mainInput.attr( 'id' ) ? $mainInput.attr( 'id' ) : $mainInput.attr( 'name' );

            if ( ! fieldId ) {
                return false;
            }

            if ( currentProvider && fieldId.toLowerCase().indexOf( '-p-' + currentProvider + '-' ) >= 0 ) {
                // Remove the shipping method name prefix
                return fieldId.substring( fieldId.lastIndexOf( '-p-' + currentProvider + '-' ), fieldId.length );
            }

            return fieldId;
        },

        onShippingMethodOpen: function( e, t ) {
            var self = shipments.admin.shipping_provider_method;

            if ( 'wc-modal-shipping-method-settings' === t ) {
                var $wrapper = $( '.wc-modal-shipping-method-settings' ),
                    $lazy    = $wrapper.find( '.wc-stc-shipping-provider-method-lazy-settings' );

                if ( $lazy.length > 0 ) {
                    self.loadProviderSettings( $wrapper, $lazy );
                } else if ( $wrapper.find( 'select[id$=shipping_provider]' ).length > 0 ) {
                    $wrapper.find( 'select[id$=shipping_provider]' ).trigger( 'change' );
                }
            }
        },

        /**
         * Provider settings are lazy-loaded to prevent rendering (large) settings
         * for each shipping method while listing shipping zones/methods.
         */
        loadProviderSettings: function( $wrapper, $lazy ) {
            var self    = shipments.admin.shipping_provider_method,
                $button = $wrapper.parents( '.wc-backbone-modal-content' ).find( '#btn-ok' );

            $button.prop( 'disabled', true );

            $.ajax( {
                url: self.params.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'woocommerce_stc_get_shipping_method_provider_settings',
                    instance_id: $lazy.data( 'instance-id' ),
                    security: self.params.provider_settings_nonce
                },
                success: function( response ) {
                    if ( response.success ) {
                        var html = response.data.html;

                        /**
                         * Woo reformats the settings HTML (e.g. replaces tables) within the modal.
                         * Apply the same formatting to the lazy-loaded settings.
                         */
                        if ( $wrapper.find( '.wc-shipping-zone-method-fields' ).length > 0 ) {
                            html = self.reformatSettingsHTML( html );
                        }

                        $lazy.replaceWith( html );

                        $( document.body ).trigger( 'wc-enhanced-select-init' );
                        $( document.body ).trigger( 'init_tooltips' );
                        $wrapper.find( 'select[id$=shipping_provider]' ).trigger( 'change' );
                    } else {
                        self.onLoadProviderSettingsError( $lazy );
                    }
                },
                error: function() {
                    self.onLoadProviderSettingsError( $lazy );
                },
                complete: function() {
                    $button.prop( 'disabled', false );
                }
            } );
        },

        onLoadProviderSettingsError: function( $lazy ) {
            var self = shipments.admin.shipping_provider_method;

            $lazy.html( '<div class="notice notice-error inline"><p>' + self.params.i18n_load_error + '</p></div>' );
        },

        /**
         * Mirrors the formatting Woo applies to shipping method settings within the modal.
         *
         * @see wc-shipping-zone-methods.js reformatSettingsHTML()
         */
        reformatSettingsHTML: function( html ) {
            var $content = $( '<div>' + html + '</div>' );

            $content.find( 'table.form-table' ).each( function() {
                var $table = $( this ),
                    $div   = $( '<div class="wc-shipping-zone-method-fields" />' );

                $div.html( $table.html() );
                $table.replaceWith( $div );
            } );

            $content.find( 'label' ).each( function() {
                var $label   = $( this ),
                    $helpTip = $label.find( '.woocommerce-help-tip' ),
                    id       = $label.attr( 'for' );

                if ( 0 === $helpTip.length || ! id ) {
                    return;
                }

                var $fieldset = $content.find( '[id="' + id + '"]' ).closest( 'fieldset' );

                if ( $fieldset.length && 0 === $fieldset.find( '.wc-shipping-zone-method-fields-help-text' ).length ) {
                    $fieldset.append( $( '<div class="wc-shipping-zone-method-fields-help-text" />' ).html( $helpTip.data( 'tip' ) ) );
                }
            } );

            return $content.html();
        },

        showOrHideAll: function() {
            var self       = shipments.admin.shipping_provider_method,
                $select    = $( this ),
                $form      = $select.parents( 'form' );

            self.currentProvider = $select.val();

            $form.find( '.wc-stc-shipping-provider-method-tabs' ).hide();
            $form.find( '.wc-stc-shipping-provider-method-tab-content' ).removeClass( 'tab-content-active' );

            if ( self.currentProvider.length > 0 ) {
                $form.find( '.wc-stc-shipping-provider-method-tabs[data-provider="' + self.currentProvider + '"]' ).show();
                $form.find( '.wc-stc-shipping-provider-method-tabs[data-provider="' + self.currentProvider + '"] .nav-tab-wrapper' ).find( 'a.nav-tab:first' ).trigger( 'click' );
            }
        }
    };

    $( document ).ready( function() {
        shipments.admin.shipping_provider_method.init();
    });

})( jQuery, window.shiptastic );
