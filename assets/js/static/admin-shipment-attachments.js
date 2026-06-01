window.shiptastic = window.shiptastic || {};
window.shiptastic.admin = window.shiptastic.admin || {};

( function( $, shipments ) {

    /**
     * Core
     */
    shipments.admin.shipment_attachments = {
        params: {},
        isTableView: false,

        init: function() {
            var self = shipments.admin.shipment_attachments;

            self.isTableView = $( 'table.shipments' ).length > 0;
            self.params = wc_shiptastic_admin_shipment_attachments_params;

            $( document )
                .on( 'drop', '#panel-order-shipments .wc-stc-shipment-action-upload-drop', self.onDropUpload )
                .on( 'click', '#panel-order-shipments .upload_attachment, table.shipments tr.shipment .upload_attachment', self.onUploadAttachment )
                .on( 'change', '#panel-order-shipments .wc-stc-shipment-upload-attachment, table.shipments tr.shipment .wc-stc-shipment-upload-attachment', self.onChangeAttachment )
                .on( 'click', '#panel-order-shipments .delete_attachment, table.shipments tr.shipment .delete_attachment', self.onRemoveAttachment )
                .on( 'click', '#panel-order-shipments .create_attachment, table.shipments tr.shipment .create_attachment', self.onCreateAttachment );

            $( document ).on( 'dragover dragenter', '#panel-order-shipments .wc-stc-shipment-action-upload-drop', function( e ) {
                let $target = $( e.target ),
                    $wrapper = $target.parents( '.wc-stc-shipment-attachment' );

                $wrapper.addClass( 'dropzone' );

                e.stopPropagation();
                e.preventDefault();
            });

            $( document ).on( 'dragleave', '#panel-order-shipments .wc-stc-shipment-action-upload-drop', function( e ) {
                let $target = $( e.target ),
                    $wrapper = $target.parents( '.wc-stc-shipment-attachment' );

                $wrapper.removeClass( 'dropzone' );

                e.stopPropagation();
                e.preventDefault();
            } );
        },

        getShipment: function( $target ) {
            return $target.parents( '.order-shipment, .shipment' );
        },

        getShipmentId: function( $target ) {
            let self = shipments.admin.shipment_attachments;

            return self.getShipment( $target ).data( 'shipment' );
        },

        blockAttachment: function() {
            self = shipments.admin.shipment_attachments;

            if ( self.isTableView ) {
                $( this ).addClass( 'button-disabled' );
                $( this ).find( '.spinner' ).remove();
                $( this ).append( '<span class="spinner is-active"></span>' );
                $( this ).addClass( 'loading' );
            } else {
                $( this ).parents( '.wc-stc-shipment-attachment' ).block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });
            }
        },

        unblockAttachment: function() {
            if ( self.isTableView ) {
                $( this ).find( '.spinner' ).remove();
                $( this ).removeClass( 'button-disabled' );
                $( this ).removeClass( 'loading' );
            } else {
                $( this ).find( '.wc-stc-shipment-attachment' ).unblock();
            }
        },

        onChangeAttachment: function( e ) {
            let $target = $( e.target ),
                self = shipments.admin.shipment_attachments,
                $shipment = self.getShipment( $target ),
                $action = $shipment.find( '.wc-stc-shipment-action-button.upload_attachment[data-attachment-type="' + $target.data( 'attachment-type' ) + '"]' );

            // Get all files that are dropped
            const files = e.originalEvent.target.files || e.originalEvent.dataTransfer.files;
            const file = files[0];

            self.uploadAttachment.call( $action, file );

            return false;
        },

        getWrapper: function() {
            let self = shipments.admin.shipment_attachments;

            return self.isTableView ? shipments.admin.shipments_table : shipments.admin.shipments;
        },

        uploadAttachment: function( file = undefined ) {
            let self = shipments.admin.shipment_attachments,
                $target = $( this ),
                attachmentType = $target.data( 'attachment-type' ),
                displayFor = $target.data( 'display-for' ) ? $target.data( 'display-for' ) : 'details',
                $shipment = self.getShipment( $target ),
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'       : 'woocommerce_stc_upload_shipment_attachment',
                'shipment_id'  : shipmentId,
                'display_for'  : displayFor,
                'security'     : self.params.upload_attachment_nonce
            };

            if ( file ) {
                const $input = $shipment.find( '.wc-stc-shipment-upload-attachment[data-attachment-type="' + attachmentType + '"]' );

                params[ $input.prop('name') ] = file;
            }

            self.blockAttachment.call( $target );

            self.getWrapper().doAjax.call( $target, params, self.unblockAttachment, self.unblockAttachment );
        },

        onDropUpload: function( e ) {
            let $target = $( e.target ),
                $wrapper = $target.hasClass( 'wc-stc-shipment-attachment' ) ? $target : $target.parents( '.wc-stc-shipment-attachment' ),
                $button = $wrapper.find( '.wc-stc-shipment-action-button-upload_attachment' ),
                self = shipments.admin.shipment_attachments;

            e.stopPropagation();
            e.preventDefault();

            // Get all files that are dropped
            const files = e.originalEvent.target.files || e.originalEvent.dataTransfer.files;
            const file = files[0];

            self.uploadAttachment.call( $button, file );

            $wrapper.removeClass( 'dropzone' );

            return false;
        },

        onUploadAttachment: function( e ) {
            let $target = $( e.target ),
                self = shipments.admin.shipment_attachments;

            self.getShipment( $target ).find( '.wc-stc-shipment-upload-attachment[data-attachment-type="' + $target.data( 'attachment-type' ) + '"]' ).trigger( 'click' );

            return false;
        },

        onCreateAttachment: function( e ) {
            let self = shipments.admin.shipment_attachments;
            let $target = $( e.target );

            self.createAttachment.call( $target );

            return false;
        },

        createAttachment: function() {
            let self = shipments.admin.shipment_attachments,
                $target = $( this ),
                attachmentType = $target.data( 'attachment-type' ),
                displayFor = $target.data( 'display-for' ) ? $target.data( 'display-for' ) : 'details',
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'         : 'woocommerce_stc_create_shipment_attachment',
                'shipment_id'    : shipmentId,
                'attachment_type': attachmentType,
                'display_for'    : displayFor,
                'security'       : self.params.create_attachment_nonce
            };

            self.blockAttachment.call( $target );

            self.getWrapper().doAjax.call( $target, params, self.unblockAttachment, self.unblockAttachment );
        },

        onRemoveAttachment: function( e ) {
            let self = shipments.admin.shipment_attachments;
            let $target = $( e.target );

            var answer = window.confirm( self.params.i18n_remove_attachment_notice );

            if ( answer ) {
                self.removeAttachment.call( $target );
            }

            return false;
        },

        removeAttachment: function() {
            let self = shipments.admin.shipment_attachments,
                $target = $( this ),
                attachmentType = $target.data( 'attachment-type' ),
                displayFor = $target.data( 'display-for' ) ? $target.data( 'display-for' ) : 'details',
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'         : 'woocommerce_stc_remove_shipment_attachment',
                'shipment_id'    : shipmentId,
                'attachment_type': attachmentType,
                'display_for'    : displayFor,
                'security'       : self.params.remove_attachment_nonce
            };

            self.blockAttachment.call( $target );
            self.getWrapper().doAjax.call( $target, params, self.unblockAttachment, self.unblockAttachment );
        }
    };

    $( document ).ready( function() {
        shipments.admin.shipment_attachments.init();
    });

})( jQuery, window.shiptastic );