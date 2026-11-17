/**
 * Packaging suggestions (client-side bin packing via binpackingjs).
 *
 * Shipments are composed client-side (shipments overlay, offline-capable), so the best fitting packaging per shipment
 * is computed in the browser. The ranking mirrors the server (`Packing\PackagingSorter`,
 * `DataStores\Packaging::find_best_match_for_shipment()`):
 *
 * 1. only packaging available for the shipment (shipping provider, shipping class restrictions) in which all items fit,
 * 2. the default packaging (setting) in case it fits,
 * 3. otherwise: packaging restricted to shipping classes first, smallest inner volume, smallest empty weight,
 *    smallest max. content weight, lowest costs.
 *
 * Units: dimensions in mm, weights in g (same as the server-side packer), see ShipmentLayout::get_packing_data().
 *
 * - Packaging: { id, title, packing: { width, length, depth (inner), emptyWeight, maxWeight (content, 0 = no limit),
 *   costs, shippingClasses (restricted to, empty = all) }, providers (available for, empty = all) }
 * - Items: { id, name, quantity, packing: { width, length, depth, weight, shippingClass } }. Items without dimensions
 *   are checked by weight only.
 *
 * Pure functions without store access (tested via node, see tests/js).
 */
import { pack3D } from '../static/binpackingjs/3d/index.js';

/**
 * Up to this number of units the items are packed (3D), above only volume and weight are checked.
 */
export const DEFAULT_MAX_ITEMS = 100;

const MEMO_LIMIT = 250;
const memo = new Map();

const num = ( value ) => Math.max( 0, Number( value ) || 0 );

function packingOf( entity ) {
    return entity && entity.packing ? entity.packing : {};
}

function maxWeightOf( packaging ) {
    const max = num( packingOf( packaging ).maxWeight );
    return max > 0 ? max : Infinity;
}

function innerVolume( packaging ) {
    const packing = packingOf( packaging );
    return num( packing.width ) * num( packing.length ) * num( packing.depth );
}

function sortedDimensions( packing ) {
    return [ num( packing.width ), num( packing.length ), num( packing.depth ) ].sort( ( a, b ) => b - a );
}

/**
 * @param {Object} item
 * @return {boolean} Whether the item has all dimensions (otherwise it is checked by weight only).
 */
export function hasDimensions( item ) {
    const packing = packingOf( item );
    return num( packing.width ) > 0 && num( packing.length ) > 0 && num( packing.depth ) > 0;
}

/**
 * @param {Object[]} items Items incl. quantity.
 * @return {Object[]} One entry per unit: { id, width, length, depth, weight, shippingClass, hasDimensions }.
 */
export function toUnits( items ) {
    const units = [];

    ( items || [] ).forEach( ( item ) => {
        const packing = packingOf( item );
        const quantity = Math.max( 0, parseInt( item.quantity, 10 ) || 0 );

        for ( let i = 0; i < quantity; i++ ) {
            units.push( {
                id: item.id,
                width: num( packing.width ),
                length: num( packing.length ),
                depth: num( packing.depth ),
                weight: num( packing.weight ),
                shippingClass: parseInt( packing.shippingClass, 10 ) || 0,
                hasDimensions: hasDimensions( item ),
            } );
        }
    } );

    return units;
}

/**
 * @param {Object} packaging
 * @param {string} provider
 * @return {boolean}
 */
export function supportsProvider( packaging, provider ) {
    const providers = packaging.providers || [];
    return ! provider || 0 === providers.length || providers.includes( provider );
}

/**
 * Same as Packaging::supports_shipping_class(): restricted packaging only holds items of these shipping classes.
 *
 * @param {Object} packaging
 * @param {number} shippingClass
 * @return {boolean}
 */
export function supportsShippingClass( packaging, shippingClass ) {
    const classes = ( packingOf( packaging ).shippingClasses || [] ).map( ( id ) => parseInt( id, 10 ) );
    return 0 === classes.length || ( shippingClass > 0 && classes.includes( shippingClass ) );
}

/**
 * @param {Object} packaging
 * @param {Object[]} units
 * @param {string} provider
 * @return {boolean} Whether the packaging may be used for the units (restrictions only, no fit check).
 */
export function isAvailable( packaging, units, provider ) {
    return supportsProvider( packaging, provider ) && units.every( ( unit ) => supportsShippingClass( packaging, unit.shippingClass ) );
}

/**
 * Ranking of the server-side packer (PackagingSorter).
 *
 * @param {Object} a
 * @param {Object} b
 * @return {number}
 */
export function compare( a, b ) {
    const restrictedA = ( packingOf( a ).shippingClasses || [] ).length > 0;
    const restrictedB = ( packingOf( b ).shippingClasses || [] ).length > 0;

    if ( restrictedA !== restrictedB ) {
        return restrictedA ? -1 : 1;
    }

    const deciders = [
        innerVolume( a ) - innerVolume( b ),
        num( packingOf( a ).emptyWeight ) - num( packingOf( b ).emptyWeight ),
        maxWeightOf( a ) === maxWeightOf( b ) ? 0 : ( maxWeightOf( a ) < maxWeightOf( b ) ? -1 : 1 ),
        num( packingOf( a ).costs ) - num( packingOf( b ).costs ),
    ];

    return deciders.find( ( decider ) => 0 !== decider ) || 0;
}

/**
 * @param {Object} packaging
 * @param {Object} unit
 * @return {boolean} Whether a single unit fits the (empty) packaging.
 */
function unitFits( packaging, unit ) {
    if ( unit.weight > maxWeightOf( packaging ) ) {
        return false;
    }

    if ( ! unit.hasDimensions ) {
        return true;
    }

    const box = sortedDimensions( packingOf( packaging ) );
    const dims = sortedDimensions( unit );

    return dims.every( ( value, index ) => value <= box[ index ] );
}

/**
 * Checks whether all units fit the packaging: naive checks (volume, weight, each unit) first, then 3D packing (up to
 * maxItems units, weight and volume only above).
 *
 * @param {Object} packaging
 * @param {Object[]} units
 * @param {number} maxItems
 * @return {{fits: boolean, fillVolume: number, fillWeight: (number|null)}}
 */
export function checkFit( packaging, units, maxItems = DEFAULT_MAX_ITEMS ) {
    const volume = innerVolume( packaging );
    const maxWeight = maxWeightOf( packaging );
    const geometric = units.filter( ( unit ) => unit.hasDimensions );
    const itemsVolume = geometric.reduce( ( sum, unit ) => sum + unit.width * unit.length * unit.depth, 0 );
    const itemsWeight = units.reduce( ( sum, unit ) => sum + unit.weight, 0 );
    const result = {
        fits: false,
        fillVolume: volume > 0 ? itemsVolume / volume : 0,
        fillWeight: Number.isFinite( maxWeight ) ? itemsWeight / maxWeight : null,
    };

    if ( 0 === units.length || itemsWeight > maxWeight || ( geometric.length > 0 && ( volume <= 0 || itemsVolume > volume ) ) ) {
        return result;
    }

    if ( ! units.every( ( unit ) => unitFits( packaging, unit ) ) ) {
        return result;
    }

    if ( geometric.length <= 1 || units.length > maxItems ) {
        result.fits = true;
        return result;
    }

    const packing = packingOf( packaging );
    const { unfitItems } = pack3D( {
        bins: [ { name: 'packaging', width: num( packing.width ), height: num( packing.length ), depth: num( packing.depth ), maxWeight: Infinity } ],
        items: geometric.map( ( unit, index ) => ( { name: String( index ), width: unit.width, height: unit.length, depth: unit.depth, weight: 0 } ) ),
    } );

    result.fits = 0 === unfitItems.length;

    return result;
}

function memoKey( args ) {
    const items = ( args.items || [] )
        .filter( ( item ) => ( parseInt( item.quantity, 10 ) || 0 ) > 0 )
        .map( ( item ) => {
            const packing = packingOf( item );
            return [ item.id, item.quantity, packing.width, packing.length, packing.depth, packing.weight, packing.shippingClass ].join( ':' );
        } )
        .sort()
        .join( ',' );
    const packaging = ( args.packaging || [] ).map( ( candidate ) => {
        const packing = packingOf( candidate );
        return [ candidate.id, packing.width, packing.length, packing.depth, packing.emptyWeight, packing.maxWeight, packing.costs, ( packing.shippingClasses || [] ).join( '.' ), ( candidate.providers || [] ).join( '.' ) ].join( ':' );
    } ).join( ',' );

    return [ args.kind, args.provider || '', args.defaultPackagingId || 0, args.maxItems || 0, packaging, items ].join( '|' );
}

function memoize( args, compute ) {
    const key = memoKey( args );

    if ( memo.has( key ) ) {
        return memo.get( key );
    }

    const value = compute();

    if ( memo.size >= MEMO_LIMIT ) {
        memo.delete( memo.keys().next().value );
    }

    memo.set( key, value );

    return value;
}

/**
 * Clears the memoized results, e.g. in case the packaging list changed.
 */
export function clearCache() {
    memo.clear();
}

/**
 * Packaging options for a shipment: fitting options first (ranked), the suggestion is the best fitting option.
 *
 * @param {Object}   args
 * @param {Object[]} args.packaging          Packaging list.
 * @param {Object[]} args.items              The shipment's items incl. quantity.
 * @param {string}   args.provider           The shipment's shipping provider.
 * @param {number}   args.defaultPackagingId Default packaging (setting).
 * @param {number}   args.maxItems           Units packed in 3D at most.
 * @return {{options: Object[], suggestion: number, tooLarge: number[], missingDimensions: number[]}}
 *          options: { id, title, available, fits, fillVolume, fillWeight }, tooLarge/missingDimensions: item ids.
 */
export function getPackagingOptions( args ) {
    return memoize( { ...args, kind: 'options' }, () => computeOptions( args ) );
}

function computeOptions( { packaging = [], items = [], provider = '', defaultPackagingId = 0, maxItems = DEFAULT_MAX_ITEMS } ) {
    const units = toUnits( items );
    const ranked = [ ...packaging ].sort( compare );
    const options = ranked.map( ( candidate ) => {
        const available = isAvailable( candidate, units, provider );
        const fit = available ? checkFit( candidate, units, maxItems ) : { fits: false, fillVolume: 0, fillWeight: null };

        return { id: candidate.id, title: candidate.title, available, fits: available && fit.fits, fillVolume: fit.fillVolume, fillWeight: fit.fillWeight };
    } );

    const fitting = options.filter( ( option ) => option.fits );
    const defaultOption = fitting.find( ( option ) => option.id === defaultPackagingId );
    const suggestion = defaultOption || fitting[ 0 ];
    const unitsAvailable = packaging.filter( ( candidate ) => supportsProvider( candidate, provider ) );
    const tooLarge = [ ...new Set( units.filter( ( unit ) => ! unitsAvailable.some( ( candidate ) => supportsShippingClass( candidate, unit.shippingClass ) && unitFits( candidate, unit ) ) ).map( ( unit ) => unit.id ) ) ];

    return {
        options: [ ...fitting, ...options.filter( ( option ) => ! option.fits ) ],
        suggestion: suggestion ? suggestion.id : 0,
        tooLarge,
        missingDimensions: [ ...new Set( units.filter( ( unit ) => ! unit.hasDimensions ).map( ( unit ) => unit.id ) ) ],
    };
}

function groupUnits( units ) {
    return units.reduce( ( items, unit ) => {
        items[ unit.id ] = ( items[ unit.id ] || 0 ) + 1;
        return items;
    }, {} );
}

/**
 * Distributes units into as few packages of the given packaging as possible.
 *
 * @param {Object}   packaging
 * @param {Object[]} units
 * @param {number}   maxItems
 * @return {Object[][]|null} Units per package, null in case they do not fit (even one unit per package).
 */
function distribute( packaging, units, maxItems ) {
    if ( units.length > maxItems ) {
        // Weight and volume only (fill the packages one after another).
        const groups = [];
        let current = [];

        units.forEach( ( unit ) => {
            if ( current.length > 0 && ! checkFit( packaging, [ ...current, unit ], maxItems ).fits ) {
                groups.push( current );
                current = [];
            }

            current.push( unit );
        } );

        if ( current.length > 0 ) {
            groups.push( current );
        }

        return groups.every( ( group ) => checkFit( packaging, group, maxItems ).fits ) ? groups : null;
    }

    const packing = packingOf( packaging );
    const maxWeight = maxWeightOf( packaging );

    for ( let count = 2; count <= units.length; count++ ) {
        const { packedBins, unfitItems } = pack3D( {
            bins: Array.from( { length: count }, ( value, index ) => ( {
                name: 'package-' + index,
                width: num( packing.width ) || 1,
                height: num( packing.length ) || 1,
                depth: num( packing.depth ) || 1,
                maxWeight,
            } ) ),
            items: units.map( ( unit, index ) => ( {
                name: String( index ),
                unit,
                // Items without dimensions are checked by weight only.
                width: unit.hasDimensions ? unit.width : 0,
                height: unit.hasDimensions ? unit.length : 0,
                depth: unit.hasDimensions ? unit.depth : 0,
                weight: unit.weight,
            } ) ),
        } );

        if ( 0 === unfitItems.length ) {
            return packedBins.filter( ( bin ) => bin.items.length > 0 ).map( ( bin ) => bin.items.map( ( packed ) => packed.sourceItem.unit ) );
        }
    }

    return null;
}

/**
 * Proposes the shipments for items assigned for the first time (no shipments yet): one shipment in case all items
 * fit one packaging, otherwise as few shipments as possible (largest available packaging first, then the best
 * fitting packaging per shipment). Items fitting no packaging are proposed as a separate shipment without packaging.
 *
 * @param {Object} args See getPackagingOptions().
 * @return {{shipments: {items: Object, packaging_id: number}[], tooLarge: number[]}}
 */
export function proposeShipments( args ) {
    return memoize( { ...args, kind: 'split' }, () => computeProposal( args ) );
}

function computeProposal( args ) {
    const { packaging = [], items = [], provider = '', maxItems = DEFAULT_MAX_ITEMS } = args;
    const units = toUnits( items );

    if ( 0 === units.length ) {
        return { shipments: [], tooLarge: [] };
    }

    const all = getPackagingOptions( { ...args, items } );

    if ( all.suggestion || 0 === packaging.length ) {
        return { shipments: [ { items: groupUnits( units ), packaging_id: all.suggestion } ], tooLarge: all.tooLarge };
    }

    const shipments = [];
    const fittable = units.filter( ( unit ) => ! all.tooLarge.includes( unit.id ) );
    const tooLarge = units.filter( ( unit ) => all.tooLarge.includes( unit.id ) );

    const addGroup = ( group ) => {
        const groupItems = Object.entries( groupUnits( group ) ).map( ( [ id, quantity ] ) => ( { ...items.find( ( item ) => String( item.id ) === String( id ) ), quantity } ) );
        shipments.push( { items: groupUnits( group ), packaging_id: getPackagingOptions( { ...args, items: groupItems } ).suggestion } );
    };

    // Units of one shipping class may only share restricted packaging: split by shipping class in case no packaging holds all.
    const largestFor = ( group ) => packaging.filter( ( candidate ) => isAvailable( candidate, group, provider ) ).sort( ( a, b ) => innerVolume( b ) - innerVolume( a ) || maxWeightOf( b ) - maxWeightOf( a ) )[ 0 ];
    const groups = largestFor( fittable ) ? [ fittable ] : Object.values( fittable.reduce( ( byClass, unit ) => {
        ( byClass[ unit.shippingClass ] = byClass[ unit.shippingClass ] || [] ).push( unit );
        return byClass;
    }, {} ) );

    groups.forEach( ( group ) => {
        const largest = largestFor( group );
        const packages = largest ? distribute( largest, group, maxItems ) : null;

        if ( packages ) {
            packages.forEach( addGroup );
        } else {
            // E.g. the largest packaging does not hold the heaviest unit: one unit per shipment.
            group.forEach( ( unit ) => addGroup( [ unit ] ) );
        }
    } );

    if ( tooLarge.length > 0 ) {
        shipments.push( { items: groupUnits( tooLarge ), packaging_id: 0 } );
    }

    return { shipments, tooLarge: all.tooLarge };
}

/**
 * Packaging to use after the items (or the provider) of a shipment changed: a manual choice is kept as long as it
 * still fits, otherwise the suggestion is applied.
 *
 * @param {Object} args
 * @param {number} args.current The shipment's packaging.
 * @param {boolean} args.manual Whether the packaging was chosen manually (or stored before).
 * @param {Object} args.result  Result of getPackagingOptions().
 * @return {{id: number, suggested: boolean, replaced: number}} replaced: the manual choice which no longer fits.
 */
export function choosePackaging( { current = 0, manual = false, result } ) {
    const currentOption = result.options.find( ( option ) => option.id === current );

    if ( manual && current > 0 && currentOption && currentOption.fits ) {
        return { id: current, suggested: false, replaced: 0 };
    }

    const id = result.suggestion;

    return { id, suggested: id > 0, replaced: manual && current > 0 && current !== id ? current : 0 };
}
