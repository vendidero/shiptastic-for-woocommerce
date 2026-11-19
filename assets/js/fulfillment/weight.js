/**
 * Weight texts of items (check list, shipments overlay), in the shop's weight unit and the user's number format.
 */
import { getConfig } from '@wordpress/interactivity';

const NAMESPACE = 'shiptastic/fulfillment';

let formatter = null;

const sprintf = ( text, ...values ) => {
    let index = 0;
    return String( text || '' ).replace( /%(\d+\$)?[sd]/g, ( match, position ) => String( position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ] ) );
};

/**
 * @param {number} weight
 * @return {string} E.g. "1,25 kg".
 */
export function formatWeight( weight ) {
    if ( ! formatter ) {
        try {
            formatter = new Intl.NumberFormat( document.documentElement.lang || undefined, { maximumFractionDigits: 2 } );
        } catch ( error ) {
            formatter = new Intl.NumberFormat( undefined, { maximumFractionDigits: 2 } );
        }
    }

    return formatter.format( Math.round( ( parseFloat( weight ) || 0 ) * 100 ) / 100 ) + ' ' + ( getConfig( NAMESPACE ).weightUnit || '' );
}

/**
 * @param {number} unitWeight Weight of one unit.
 * @param {number} quantity
 * @return {string} "0,5 kg each" (no quantity yet), "0,5 kg" (one unit), "3 × 0,5 kg = 1,5 kg", empty without weight.
 */
export function itemWeightText( unitWeight, quantity ) {
    const weight = parseFloat( unitWeight ) || 0;
    const i18n = getConfig( NAMESPACE ).i18n;

    if ( weight <= 0 ) {
        return '';
    }

    if ( ! quantity || quantity < 1 ) {
        return sprintf( i18n.weightEach, formatWeight( weight ) );
    }

    if ( 1 === quantity ) {
        return formatWeight( weight );
    }

    return sprintf( i18n.weightTimes, quantity, formatWeight( weight ), formatWeight( weight * quantity ) );
}
