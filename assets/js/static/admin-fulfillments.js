// view.js
import { store, withSyncEvent } from '@wordpress/interactivity';

store( 'shiptastic/fulfillments', {
    actions: {
        prefetch: function* ( event ) {
            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.prefetch( event.target.href );
        },
        prev: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( event.target.href );
        } ),
        next: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( event.target.href );
        } ),
    },
} );