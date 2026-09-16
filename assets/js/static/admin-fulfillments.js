// view.js
import { store, withSyncEvent, getContext, getServerContext } from '@wordpress/interactivity';

const { state, actions } = store( 'shiptastic/fulfillments', {
    actions: {
        prefetch: function* ( event ) {
            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.prefetch( event.target.href );
        },
        prevOrder: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( event.target.href, { force: true } );
        } ),
        nextOrder: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( event.target.href, { force: true } );
        } ),
        goToAction: withSyncEvent( function* ( event ) {
            event.preventDefault();

            const { actions } = yield import(
                '@wordpress/interactivity-router'
                );
            yield actions.navigate( event.target.href );
        } ),
    },
    callbacks: {
        updateContext() {
            const clientContext = getContext();
            const serverContext = getServerContext();
            // const clientState   = getState();

            console.log('jaa');
            console.log(state);
            console.log(clientContext);
        },
    }
} );