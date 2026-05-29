window.shiptastic = window.shiptastic || {};
window.shiptastic.admin = window.shiptastic.admin || {};

( function( $, shipments ) {

    /**
     * Core
     */
    shipments.admin.shipment_attachments = {
        params: {},

        init: function() {
            var self = shipments.admin.shipment_attachments;

            $( document )
                .on( 'drop', '#panel-order-shipments .wc-stc-shipment-action-upload-drop', self.onDropUpload )
                .on( 'click', '#panel-order-shipments .upload_attachment', self.onUploadAttachment )
                .on( 'change', '#panel-order-shipments .wc-stc-shipment-upload-attachment', self.onChangeAttachment )
                .on( 'click', '#panel-order-shipments .delete_attachment', self.onRemoveAttachment )
                .on( 'click', '#panel-order-shipments .create_attachment', self.onCreateAttachment );

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
            return $target.parents( '.order-shipment' );
        },

        getShipmentId: function( $target ) {
            let self = shipments.admin.shipment_attachments;

            return self.getShipment( $target ).data( 'shipment' );
        },

        blockAttachment: function() {
            $( this ).parents( '.wc-stc-shipment-attachment' ).block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
        },

        unblockAttachment: function() {
            $( this ).parents( '.wc-stc-shipment-attachment' ).unblock();
        },

        onChangeAttachment: function( e ) {
            let $target = $( e.target ),
                self = shipments.admin.shipment_attachments,
                $action = $target.prev( '.wc-stc-shipment-action-button' );

            self.uploadAttachment.call( $action );

            return false;
        },

        uploadAttachment: function( file = undefined ) {
            let self = shipments.admin.shipment_attachments,
                $target = $( this ),
                attachmentType = $target.data( 'attachment-type' ),
                $shipment = self.getShipment( $target ),
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'       : 'woocommerce_stc_upload_shipment_attachment',
                'shipment_id'  : shipmentId,
                'security'     : shipments.admin.shipments.getParams().upload_attachment_nonce
            };

            if ( file ) {
                const $attachmentWrapper = $shipment.find( '.wc-stc-shipment-attachment-' + attachmentType );

                params[ $attachmentWrapper.find('.wc-stc-shipment-upload-attachment').prop('name') ] = file;
            }

            self.blockAttachment.call( $target );
            shipments.admin.shipments.doAjax( params, self.unblockAttachment, self.unblockAttachment );
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
            let $target = $( e.target );

            $target.next( '.wc-stc-shipment-upload-attachment' ).trigger( 'click' );

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
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'         : 'woocommerce_stc_create_shipment_attachment',
                'shipment_id'    : shipmentId,
                'attachment_type': attachmentType,
                'security'       : shipments.admin.shipments.getParams().create_attachment_nonce
            };

            self.blockAttachment.call( $target );

            shipments.admin.shipments.doAjax( params, self.unblockAttachment, self.unblockAttachment );
        },

        onRemoveAttachment: function( e ) {
            let self = shipments.admin.shipment_attachments;
            let $target = $( e.target );

            var answer = window.confirm( shipments.admin.shipments.getParams().i18n_remove_attachment_notice );

            if ( answer ) {
                self.removeAttachment.call( $target );
            }

            return false;
        },

        removeAttachment: function() {
            let self = shipments.admin.shipment_attachments,
                $target = $( this ),
                attachmentType = $target.data( 'attachment-type' ),
                shipmentId = self.getShipmentId( $target );

            let params = {
                'action'         : 'woocommerce_stc_remove_shipment_attachment',
                'shipment_id'    : shipmentId,
                'attachment_type': attachmentType,
                'security'       : shipments.admin.shipments.getParams().remove_attachment_nonce
            };

            self.blockAttachment.call( $target );
            shipments.admin.shipments.doAjax( params, self.unblockAttachment, self.unblockAttachment );
        }
    };

    $( document ).ready( function() {
        shipments.admin.shipment_attachments.init();
    });

})( jQuery, window.shiptastic );