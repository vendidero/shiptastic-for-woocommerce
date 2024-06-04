
window.germanized = window.germanized || {};
window.germanized.shipments_pickup_locations = window.germanized.shipments_pickup_locations || {};

( function( $, germanized ) {

    /**
     * Core
     */
    germanized.shipments_pickup_locations = {

        params: {},
        pickupLocations: {},
        available: false,

        init: function () {
            var self  = germanized.shipments_pickup_locations;
            self.params  = wc_gzd_shipments_pickup_locations_params;

            var $pickupSelect = self.getPickupLocationSelect();

            if ( $pickupSelect.length > 0 ) {
                self.pickupLocations = $pickupSelect.data( 'locations' );
            }

            if ( $( '#current_pickup_location' ).length > 0 ) {
                self.available = $( '.choose-pickup-location:visible' ).length > 0 || $( '.currently-shipping-to:visible' ).length > 0;

                $( document.body ).on( 'updated_checkout', self.afterRefreshCheckout );
                $( document ).on( 'change', '#ship-to-different-address-checkbox', self.onSelectDifferentShipping );
                $( document ).on( 'submit', '#wc-gzd-shipments-pickup-location-search-form', self.onSearch );
                $( document ).on( 'click', '.submit-pickup-location', self.onSelectPickupLocation );
                $( document ).on( 'change', '#current_pickup_location', self.onChangeCurrentPickupLocation );
                $( document ).on( 'click', '.pickup-location-remove', self.onRemovePickupLocation );
                $( document ).on( 'change', '#pickup_location', self.onChangePickupLocation );
                $( document ).on( 'change', '#billing_postcode, #shipping_postcode', self.onChangeAddress );

                self.onChangeCurrentPickupLocation();
                self.onChangePickupLocation();
                self.maybeInitSelect2();
            }
        },

        onChangeAddress: function() {
            var self= germanized.shipments_pickup_locations,
                postcode = $( '#shipping_postcode:visible' ).val() ? $( '#shipping_postcode:visible' ).val() : $( '#billing_postcode' ).val();

            $( '#pickup-location-postcode' ).val( postcode );
        },

        onChangePickupLocation: function() {
            var self= germanized.shipments_pickup_locations,
                $pickupSelect = self.getPickupLocationSelect();

            if ( $pickupSelect.val() ) {
                $( '.pickup-location-search-actions' ).find( '.submit-pickup-location' ).removeClass( 'hidden' ).show();
            } else {
                $( '.pickup-location-search-actions' ).find( '.submit-pickup-location' ).addClass( 'hidden' ).hide();
            }
        },

        hasPickupLocationDelivery: function() {
            var self     = germanized.shipments_pickup_locations,
                $current = $( '#current_pickup_location' ),
                currentCode = $current.val();

            if ( currentCode ) {
                return true;
            }

            return false;
        },

        disablePickupLocationDelivery: function() {
            var self= germanized.shipments_pickup_locations,
                $modal = $( '.wc-gzd-modal-content[data-id="pickup-location"].active' );

            $( '.wc-gzd-shipments-managed-by-pickup-location' ).val( '' );
            $( '#current_pickup_location' ).val( '' ).trigger( 'change' );

            if ( $modal.length > 0 ) {
                $modal.find( '.wc-gzd-modal-close' ).trigger( 'click' );
            }
        },

        onRemovePickupLocation: function() {
            var self= germanized.shipments_pickup_locations;

            self.disablePickupLocationDelivery();

            return false;
        },

        getCustomerNumberField: function() {
            return $( '#pickup_location_customer_number_field' );
        },

        onChangeCurrentPickupLocation: function() {
            var self     = germanized.shipments_pickup_locations,
                $current = $( '#current_pickup_location' ),
                currentCode = $current.val(),
                currentPickupLocation = currentCode ? self.getPickupLocation( currentCode ) : false,
                $notice = $( '.pickup_location_notice' );

            if ( currentCode && currentPickupLocation ) {
                $current.attr( 'data-current-location', currentPickupLocation );

                self.replaceShippingAddress( currentPickupLocation.address_replacements );
                self.updateCustomerNumberField( currentPickupLocation );

                $notice.find( '.pickup-location-manage-link' ).text( currentPickupLocation.label );
                $notice.find( '.currently-shipping-to' ).show();
                $notice.find( '.choose-pickup-location' ).hide();

                $( '#wc-gzd-shipments-pickup-location-search-form .pickup-location-remove' ).removeClass( 'hidden' ).show();
            } else {
                $current.attr( 'data-current-location', '' );
                $current.val( '' );

                self.getCustomerNumberField().addClass( 'hidden' );
                self.getCustomerNumberField().hide();

                $( '.wc-gzd-shipments-managed-by-pickup-location' ).find( 'input[type=text]' ).val( '' );
                $( '.wc-gzd-shipments-managed-by-pickup-location' ).find( ':input' ).prop( 'readonly', false );

                $( '#wc-gzd-shipments-pickup-location-search-form .pickup-location-remove' ).addClass( 'hidden' ).hide();

                $( '.wc-gzd-shipments-managed-by-pickup-location' ).removeClass( 'wc-gzd-shipments-managed-by-pickup-location' );
                $( '.wc-gzd-shipments-managed-by-pickup-location-notice' ).remove();

                $notice.find( '.currently-shipping-to' ).hide();
                $notice.find( '.choose-pickup-location' ).show();
            }
        },

        onSearch: function() {
            var self     = germanized.shipments_pickup_locations,
                $form      = $( this ),
                params     = $form.serialize(),
                $pickupSelect = self.getPickupLocationSelect(),
                current = $pickupSelect.val();

            $( '#wc-gzd-shipments-pickup-location-search-form' ).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });

            params += '&action=woocommerce_gzd_shipments_search_pickup_locations&context=' + self.params.context;

            $.ajax({
                type: "POST",
                url:  self.params.wc_ajax_url.toString().replace('%%endpoint%%', 'woocommerce_gzd_shipments_search_pickup_locations'),
                data: params,
                success: function( data ) {
                    if ( data.success ) {
                        self.pickupLocations = data.locations;
                        self.updatePickupLocationSelect();

                        $( '#wc-gzd-shipments-pickup-location-search-form' ).unblock();
                    }
                },
                error: function( data ) {},
                dataType: 'json'
            });

            return false;
        },

        updatePickupLocationSelect: function() {
            var self     = germanized.shipments_pickup_locations,
                $pickupSelect = self.getPickupLocationSelect(),
                current = $pickupSelect.val();

            $pickupSelect.attr('data-locations', self.pickupLocations );
            $pickupSelect.find( 'option:not([value=""])' ).remove();

            $.each( self.pickupLocations, function( code, pickupLocation ) {
                var label = $( '<textarea />' ).html( pickupLocation.formatted_address ).text();
                $pickupSelect.append( $( "<option></option>" ).attr("value", code ).text( label ) );
            });

            var currentLocation = self.getPickupLocation( current );

            if ( currentLocation ) {
                $pickupSelect.find( 'option[value="' + currentLocation.code + '"' )[0].selected = true;
            }

            $pickupSelect.trigger( 'change' );
        },

        onSelectDifferentShipping: function() {
            var self= germanized.shipments_pickup_locations;

            if ( ! $( this ).is( ':checked' ) ) {
                self.disablePickupLocationDelivery();

                if ( self.isAvailable() ) {
                    $( '#billing_pickup_location_notice' ).removeClass( 'hidden' ).show();
                }
            } else {
                $( '#billing_pickup_location_notice' ).addClass( 'hidden' ).hide();
            }
        },

        maybeInitSelect2: function() {
            if ( $().selectWoo ) {
                $( 'select#pickup_location' ).each( function() {
                    var $this = $( this );

                    var select2_args = {
                        placeholder: $this.attr( 'data-placeholder' ) || $this.attr( 'placeholder' ) || '',
                        label: $this.attr( 'data-label' ) || null,
                        width: '100%',
                        dropdownCssClass: "wc-gzd-pickup-location-select-dropdown"
                    };

                    $( this )
                        .on( 'select2:select', function() {
                            $( this ).trigger( 'focus' ); // Maintain focus after select https://github.com/select2/select2/issues/4384
                        } )
                        .selectWoo( select2_args );
                });
            }
        },

        onSelectPickupLocation: function() {
            var self = germanized.shipments_pickup_locations,
                $pickupSelect  = self.getPickupLocationSelect(),
                current = $pickupSelect.val();

            $( '#current_pickup_location' ).val( current ).trigger( 'change' );
            $( this ).parents( '.wc-gzd-modal-content' ).find( '.wc-gzd-modal-close' ).trigger( 'click' );

            var scrollElement = $( '#shipping_address_1_field' );

            $.scroll_to_notices( scrollElement );

            return false;
        },

        updateCustomerNumberField: function( currentLocation ) {
            var self = germanized.shipments_pickup_locations,
                $customerNumberField = self.getCustomerNumberField();

            if ( currentLocation.supports_customer_number ) {
                // Do not replace via .text() to prevent removing inner html elements, e.g. optional label.
                $customerNumberField.find( 'label' )[0].firstChild.nodeValue = currentLocation.customer_number_field_label + ' ';

                if ( currentLocation.customer_number_is_mandatory ) {
                    if ( ! $customerNumberField.find( 'label .required' ).length ) {
                        $customerNumberField.find( 'label' ).append( ' <abbr class="required">*</abbr>' );
                    }

                    $customerNumberField.find( 'label .optional' ).hide();
                    $customerNumberField.addClass( 'validate-required' );
                } else {
                    $customerNumberField.find( 'label .required' ).remove();
                    $customerNumberField.find( 'label .optional' ).show();

                    $customerNumberField.removeClass( 'validate-required woocommerce-invalid woocommerce-invalid-required-field' );
                }

                $customerNumberField.removeClass( 'hidden' );
                $customerNumberField.show();
            } else {
                $customerNumberField.addClass( 'hidden' );
                $customerNumberField.hide();
            }
        },

        getPickupLocationSelect: function() {
            return $( '#pickup_location' );
        },

        getPickupLocation: function( locationCode ) {
            var self = germanized.shipments_pickup_locations;

            if ( self.pickupLocations.hasOwnProperty( locationCode ) ) {
                return self.pickupLocations[ locationCode ];
            } else {
                var $select = $( '#current_pickup_location' );

                if ( $select.data( 'current-location' ) ) {
                    var currentLocation = $select.data( 'current-location' );

                    if ( currentLocation.code === locationCode ) {
                        return currentLocation;
                    }
                }
            }

            return false;
        },

        afterRefreshCheckout: function( e, ajaxData ) {
            var self = germanized.shipments_pickup_locations,
                supportsPickupLocationDelivery = false;

            ajaxData = ( typeof ajaxData === 'undefined' ) ? {
                'fragments': {
                    '.gzd-shipments-pickup-location-supported': false,
                    '.gzd-shipments-pickup-locations': JSON.stringify( self.pickupLocations ),
                }
            } : ajaxData;

            if ( ajaxData.hasOwnProperty( 'fragments' ) ) {
                if ( ajaxData.fragments.hasOwnProperty( '.gzd-shipments-pickup-location-supported' ) ) {
                    supportsPickupLocationDelivery = ajaxData.fragments['.gzd-shipments-pickup-location-supported'];
                }
                if ( ajaxData.fragments.hasOwnProperty( '.gzd-shipments-pickup-locations' ) && Object.keys( self.pickupLocations ).length <= 0 ) {
                    self.pickupLocations = JSON.parse( ajaxData.fragments['.gzd-shipments-pickup-locations'] );

                    self.updatePickupLocationSelect();
                }
            }

            if ( ! supportsPickupLocationDelivery ) {
                self.disable();
            } else {
                self.enable();
            }
        },

        disable: function() {
            var self = germanized.shipments_pickup_locations;

            self.available = false;

            if ( self.hasPickupLocationDelivery() ) {
                self.disablePickupLocationDelivery();

                var $form = $( 'form.checkout' );

                if ( $form.find( '.woocommerce-NoticeGroup-updateOrderReview' ).length <= 0 ) {
                    $form.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-updateOrderReview"></div>' );
                }

                $form.find( '.woocommerce-NoticeGroup-updateOrderReview' ).prepend( '<div class="woocommerce-info">' + self.params.i18n_pickup_location_delivery_unavailable + '</div>' );

                var scrollElement = $( '.woocommerce-NoticeGroup-updateOrderReview' );

                $.scroll_to_notices( scrollElement );
            }

            $( '.pickup_location_notice' ).addClass( 'hidden' ).hide();
        },

        enable: function() {
            var self = germanized.shipments_pickup_locations;

            self.available = true;

            $( '.pickup_location_notice' ).removeClass( 'hidden' ).show();

            if ( $( '#ship-to-different-address-checkbox' ).is( ':checked' ) || self.hasPickupLocationDelivery() ) {
                $( '#billing_pickup_location_notice' ).addClass( 'hidden' ).hide();
            } else {
                $( '#billing_pickup_location_notice' ).removeClass( 'hidden' ).show();
            }
        },

        isAvailable: function() {
            var self= germanized.shipments_pickup_locations;

            return self.available;
        },

        replaceShippingAddress: function( replacements ) {
            var self = germanized.shipments_pickup_locations,
                $shipToDifferent = $( '#ship-to-different-address input' ),
            hasChanged = [];

            Object.keys( replacements ).forEach( addressField => {
                var value = replacements[ addressField ];

                if ( value ) {
                    if ( $( '#shipping_' + addressField ).length > 0 ) {
                        if ( $( '#shipping_' + addressField ).val() !== value ) {
                            hasChanged.push( addressField );
                        }

                        $( '#shipping_' + addressField ).val( value );
                        $( '#shipping_' + addressField ).prop( 'readonly', true );

                        if ( 'country' === addressField ) {
                            $( '#shipping_' + addressField ).trigger( 'change' ); // select2 needs a change event
                        }

                        var $row = $( '#shipping_' + addressField + '_field' );

                        if ( $row.length > 0 ) {
                            $row.addClass( 'wc-gzd-shipments-managed-by-pickup-location' );

                            if ( $row.find( '.wc-gzd-shipments-managed-by-pickup-location-notice' ).length <= 0 ) {
                                $row.find( 'label' ).after( '<span class="wc-gzd-shipments-managed-by-pickup-location-notice">' + self.params.i18n_managed_by_pickup_location + '</span>' );
                            }
                        } else {
                            $( '#shipping_' + addressField ).addClass( 'wc-gzd-shipments-managed-by-pickup-location' );
                        }
                    }
                }
            });

            if ( ! $shipToDifferent.is( ':checked' ) ) {
                $shipToDifferent.prop( 'checked', true );
                $shipToDifferent.trigger( 'change' );
            }

            if ( hasChanged.length > 0 && $.inArray( "postcode", hasChanged ) !== -1 ) {
                $( '#shipping_postcode' ).trigger( 'change' );
            }
        }
    };

    $( document ).ready( function() {
        germanized.shipments_pickup_locations.init();
    });

})( jQuery, window.germanized );
