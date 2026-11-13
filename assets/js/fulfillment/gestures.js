/**
 * Pointer based gestures (touch, pen and mouse), independent of the store:
 *
 * - Swipe: elements with `data-stc-swipe` follow a horizontal swipe and dispatch a `stcswipe` event
 *   (detail.direction: left|right) once released beyond the threshold. Vertical scrolling keeps working
 *   (CSS touch-action: pan-y).
 * - Drag & drop: pressing a `data-stc-drag-handle` within a `data-stc-draggable` element drags a ghost of the element.
 *   Drop targets are elements with a `data-stc-drop` attribute (its value identifies the target). On drop, a `stcdrop`
 *   event (detail.target) is dispatched on the dragged element. Scroll containers (`data-stc-scroll`) auto-scroll
 *   near their edges.
 *
 * Bind handlers with Interactivity API directives on the same element, e.g. data-wp-on--stcswipe="actions.foo",
 * so that getContext() works within the handler.
 */
const SWIPE_THRESHOLD = 64;
const SWIPE_LOCK = 12;
const AUTOSCROLL_EDGE = 56;

let swipe = null;
let drag = null;

function onSwipeStart( event ) {
    const element = event.target.closest( '[data-stc-swipe]' );

    if ( ! element || drag || event.target.closest( 'button, input, select, textarea, a, [data-stc-drag-handle]' ) || ( 'mouse' === event.pointerType && 0 !== event.button ) ) {
        return;
    }

    swipe = { element, id: event.pointerId, x: event.clientX, y: event.clientY, dx: 0, active: false };
}

function onSwipeMove( event ) {
    if ( ! swipe || event.pointerId !== swipe.id ) {
        return;
    }

    const dx = event.clientX - swipe.x;
    const dy = event.clientY - swipe.y;

    if ( ! swipe.active ) {
        if ( Math.abs( dy ) > SWIPE_LOCK && Math.abs( dy ) > Math.abs( dx ) ) {
            // Vertical scroll.
            swipe = null;
            return;
        }

        if ( Math.abs( dx ) < SWIPE_LOCK ) {
            return;
        }

        swipe.active = true;
        swipe.element.classList.add( 'is-swiping' );
    }

    swipe.dx = dx;
    swipe.element.style.transform = `translateX(${ dx }px)`;
    swipe.element.classList.toggle( 'is-swipe-right', dx >= SWIPE_THRESHOLD );
    swipe.element.classList.toggle( 'is-swipe-left', dx <= -SWIPE_THRESHOLD );
}

function onSwipeEnd( event ) {
    if ( ! swipe || event.pointerId !== swipe.id ) {
        return;
    }

    const { element, dx, active } = swipe;
    swipe = null;

    element.style.transform = '';
    element.classList.remove( 'is-swiping', 'is-swipe-right', 'is-swipe-left' );

    if ( active && 'pointercancel' !== event.type && Math.abs( dx ) >= SWIPE_THRESHOLD ) {
        element.dispatchEvent( new CustomEvent( 'stcswipe', { bubbles: false, detail: { direction: dx > 0 ? 'right' : 'left' } } ) );
    }
}

function getDropTarget( x, y ) {
    const element = document.elementFromPoint( x, y );
    return element ? element.closest( '[data-stc-drop]' ) : null;
}

function autoScroll() {
    if ( ! drag || ! drag.scroller ) {
        return;
    }

    const rect = drag.scroller.getBoundingClientRect();
    let delta = 0;

    if ( drag.y < rect.top + AUTOSCROLL_EDGE ) {
        delta = -Math.ceil( ( rect.top + AUTOSCROLL_EDGE - drag.y ) / 4 );
    } else if ( drag.y > rect.bottom - AUTOSCROLL_EDGE ) {
        delta = Math.ceil( ( drag.y - rect.bottom + AUTOSCROLL_EDGE ) / 4 );
    }

    if ( delta ) {
        drag.scroller.scrollTop += delta;
        updateDropTarget();
    }

    drag.frame = window.requestAnimationFrame( autoScroll );
}

function updateDropTarget() {
    const target = getDropTarget( drag.x, drag.y );

    if ( target !== drag.target ) {
        if ( drag.target ) {
            drag.target.classList.remove( 'is-drop-target' );
        }

        drag.target = target;

        if ( target ) {
            target.classList.add( 'is-drop-target' );
        }
    }
}

function onDragStart( event ) {
    const handle = event.target.closest( '[data-stc-drag-handle]' );
    const element = handle ? handle.closest( '[data-stc-draggable]' ) : null;

    if ( ! element || ( 'mouse' === event.pointerType && 0 !== event.button ) ) {
        return;
    }

    event.preventDefault();
    swipe = null;

    const rect = element.getBoundingClientRect();
    const ghost = element.cloneNode( true );

    ghost.classList.add( 'stc-drag-ghost' );
    ghost.removeAttribute( 'data-stc-draggable' );
    ghost.style.width = rect.width + 'px';
    ghost.style.left = rect.left + 'px';
    ghost.style.top = rect.top + 'px';
    document.body.appendChild( ghost );

    element.classList.add( 'is-dragging' );
    handle.setPointerCapture( event.pointerId );

    drag = {
        element,
        handle,
        ghost,
        id: event.pointerId,
        offsetX: event.clientX - rect.left,
        offsetY: event.clientY - rect.top,
        x: event.clientX,
        y: event.clientY,
        target: null,
        source: element.closest( '[data-stc-drop]' ),
        scroller: element.closest( '[data-stc-scroll]' ),
        frame: 0,
    };

    drag.frame = window.requestAnimationFrame( autoScroll );
}

function onDragMove( event ) {
    if ( ! drag || event.pointerId !== drag.id ) {
        return;
    }

    event.preventDefault();

    drag.x = event.clientX;
    drag.y = event.clientY;
    drag.ghost.style.left = ( event.clientX - drag.offsetX ) + 'px';
    drag.ghost.style.top = ( event.clientY - drag.offsetY ) + 'px';

    updateDropTarget();
}

function onDragEnd( event ) {
    if ( ! drag || event.pointerId !== drag.id ) {
        return;
    }

    const { element, ghost, target, source, frame } = drag;
    drag = null;

    window.cancelAnimationFrame( frame );
    ghost.remove();
    element.classList.remove( 'is-dragging' );

    if ( target ) {
        target.classList.remove( 'is-drop-target' );
    }

    if ( 'pointercancel' !== event.type && target && target !== source ) {
        element.dispatchEvent( new CustomEvent( 'stcdrop', { bubbles: false, detail: { target: target.getAttribute( 'data-stc-drop' ) } } ) );
    }
}

let initialized = false;

export function init() {
    if ( initialized ) {
        return;
    }

    initialized = true;

    document.addEventListener( 'pointerdown', onDragStart );
    document.addEventListener( 'pointerdown', onSwipeStart );
    document.addEventListener( 'pointermove', onDragMove, { passive: false } );
    document.addEventListener( 'pointermove', onSwipeMove );
    document.addEventListener( 'pointerup', onDragEnd );
    document.addEventListener( 'pointerup', onSwipeEnd );
    document.addEventListener( 'pointercancel', onDragEnd );
    document.addEventListener( 'pointercancel', onSwipeEnd );
}
