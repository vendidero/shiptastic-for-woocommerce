(()=>{var e,t={593:(e,t,c)=>{"use strict";c.r(t);const n=window.React,o=window.wc.blocksCheckout,i=window.wp.plugins,r=window.wp.element,a=window.wp.data,s=window.wp.i18n,u=window.wp.apiFetch;var l=c.n(u);const p=window.wc.wcBlocksData;var d=c(184),m=c.n(d);const h=window.wp.htmlEntities,f=window.wcShiptastic.blocksCheckout;const k=({currentPickupLocation:e,shippingAddress:t})=>{(0,r.useEffect)((()=>{let t=null,n=null;if(null!==c.current){const{ownerDocument:e}=c.current,{defaultView:o}=e;n=o.document.getElementsByClassName("wp-block-woocommerce-checkout-shipping-address-block")[0],n&&(t=n.getElementsByClassName("wc-block-components-address-form")[0],t||(t=n.getElementsByClassName("wc-block-components-address-form-wrapper")[0]))}if(e){const c=Object.keys(e.address_replacements).length>0;if(n&&c&&!n.getElementsByClassName("managed-by-pickup-location-notice")[0]){const e=n.getElementsByClassName("wc-block-components-title")[0];e&&(e.innerHTML+='<span class="managed-by-pickup-location-notice">'+(0,s._x)('Managed by&nbsp;<a href="#current-pickup-location">pickup location</a>',"shipments","shiptastic-for-woocommerce")+"</span>")}Object.keys(e.address_replacements).forEach((c=>{if(e.address_replacements[c]&&t){const e=t.getElementsByClassName("wc-block-components-address-form__"+c)[0];if(e){e.classList.add("managed-by-pickup-location");let t=e.getElementsByTagName("input");t.length>0&&(t[0].readOnly=!0)}}}))}else{if(n){const e=n.getElementsByClassName("managed-by-pickup-location-notice")[0];e&&e.remove()}if(t){const e=t.getElementsByTagName("div");for(let t=0;t<e.length;t++){const c=e[t];if(Array.from(c.classList).includes("managed-by-pickup-location")){c.classList.remove("managed-by-pickup-location");let e=c.getElementsByTagName("input");e.length>0&&(e[0].readOnly=!1)}}}}}),[e,t]);const c=(0,r.useRef)(null);return(0,n.createElement)("div",{ref:c})},_=({currentPickupLocation:e,onRemovePickupLocation:t})=>(0,n.createElement)("h4",{className:"current-pickup-location",id:"current-pickup-location"},(0,n.createElement)("span",{className:"currently-shipping-to-title"},(0,s.sprintf)((0,s._x)("Currently shipping to: %s","shipments","shiptastic-for-woocommerce"),e.label)),(0,n.createElement)("a",{className:"pickup-location-remove",href:"#",onClick:e=>{e.preventDefault(),t()}},(0,n.createElement)("svg",{width:"24",height:"24",viewBox:"0 0 24 24",fill:"none",xmlns:"http://www.w3.org/2000/svg"},(0,n.createElement)("path",{d:"M12 13.06l3.712 3.713 1.061-1.06L13.061 12l3.712-3.712-1.06-1.06L12 10.938 8.288 7.227l-1.061 1.06L10.939 12l-3.712 3.712 1.06 1.061L12 13.061z",fill:"currentColor"})))),g=({isAvailable:e,pickupLocationOptions:t,currentPickupLocation:c,getPickupLocationByCode:i,onChangePickupLocation:r,onRemovePickupLocation:a,onChangePickupLocationCustomerNumber:u,currentPickupLocationCustomerNumber:l,isSearching:p,pickupLocationSearchAddress:d,onChangePickupLocationSearch:h})=>{const k=t.length>0;return e?(0,n.createElement)("div",{className:"wc-shiptastic-pickup-location-delivery"},!c&&(0,n.createElement)("h4",null,(0,n.createElement)("span",{className:"pickup-location-notice-title"},(0,s._x)("Not at home? Choose a pickup location","shipments","shiptastic-for-woocommerce"))),c&&(0,n.createElement)(_,{currentPickupLocation:c,onRemovePickupLocation:a}),(0,n.createElement)("div",{className:"pickup-location-search-fields"},(0,n.createElement)(o.ValidatedTextInput,{key:"pickup_location_search_address",value:d.address_1?d.address_1:"",id:"pickup-location-search-address",label:(0,s._x)("Address","shipments","shiptastic-for-woocommerce"),name:"pickup_location_search_address",onChange:e=>{h({address_1:e})}}),(0,n.createElement)(o.ValidatedTextInput,{key:"pickup_location_search_postcode",value:d.postcode?d.postcode:"",id:"pickup-location-search-postcode",label:(0,s._x)("Postcode","shipments","shiptastic-for-woocommerce"),name:"pickup_location_search_postcode",onChange:e=>{h({postcode:e})}})),(0,n.createElement)("div",{className:m()("pickup-location-search-results",{"is-searching":p})},p&&(0,n.createElement)(f.Spinner,null),k&&(0,n.createElement)(f.Combobox,{options:t,id:"pickup-location-search",key:"pickup-location-search",name:"pickup_location-search",label:(0,s._x)("Choose a pickup location","shipments","shiptastic-for-woocommerce"),errorId:"pickup-location-search",allowReset:!!c,value:c?c.code:"",onChange:e=>{r(e)},required:!1}),!k&&(0,n.createElement)("p",null,(0,s._x)("Sorry, we did not find any pickup locations nearby.","shipments","shiptastic-for-woocommerce"))),c&&c.supports_customer_number&&(0,n.createElement)(o.ValidatedTextInput,{key:"pickup_location_customer_number",value:l,id:"pickup-location-customer-number",label:c.customer_number_field_label,name:"pickup_location_customer_number",required:c.customer_number_is_mandatory,maxLength:"20",onChange:u})):null};(0,i.registerPlugin)("woocommerce-shiptastic-pickup-location-select",{render:()=>{const[e,t]=(0,r.useState)(null),[c,i]=(0,r.useState)(!1),[u,d]=(0,r.useState)(!1),[m,_]=(0,r.useState)(""),[w,v]=(0,r.useState)(null),[b,y]=(0,r.useState)({postcode:null,address_1:null}),{shippingRates:E,cartDataLoaded:C,needsShipping:S,defaultPickupLocations:P,pickupLocationDeliveryAvailable:L,defaultPickupLocation:O,defaultCustomerNumber:x,customerData:N}=(0,a.useSelect)((e=>{const t=!!e("core/editor"),c=e(p.CART_STORE_KEY),n=t?[]:c.getShippingRates(),o=c.getCartData(),i=o.extensions.hasOwnProperty("woocommerce-shiptastic")?o.extensions["woocommerce-shiptastic"]:{pickup_location_delivery_available:!1,pickup_locations:[],default_pickup_location:"",default_pickup_location_customer_number:""};return{shippingRates:n,cartDataLoaded:c.hasFinishedResolution("getCartData"),customerData:c.getCustomerData(),needsShipping:c.getNeedsShipping(),isLoadingRates:c.isCustomerDataUpdating(),isSelectingRate:c.isShippingRateBeingSelected(),pickupLocationDeliveryAvailable:i.pickup_location_delivery_available,defaultPickupLocations:i.pickup_locations,defaultPickupLocation:i.default_pickup_location,defaultCustomerNumber:i.default_pickup_location_customer_number}})),{__internalSetUseShippingAsBilling:R}=(0,a.useDispatch)(p.CHECKOUT_STORE_KEY),T=N.shippingAddress,{setShippingAddress:A,updateCustomerData:D}=(0,a.useDispatch)(p.CART_STORE_KEY),B=(0,f.getCheckoutData)(),M=L&&S,j=(0,r.useMemo)((()=>{let t=null==w?P:w;return e&&t.push(e),t}),[w,P,e]),K=(0,r.useMemo)((()=>Object.fromEntries(j.map((e=>[e.code,e])))),[j]),Y=(0,r.useCallback)((e=>K.hasOwnProperty(e)?K[e]:null),[K]),F=(0,r.useMemo)((()=>{const e=[];let t=[];for(const c of j)e.includes(c.code)||t.push({value:c.code,label:(0,h.decodeEntities)(c.formatted_address)}),e.push(c.code);return t}),[j]),U=(0,r.useMemo)((()=>{let t={address_1:T.address_1,postcode:T.postcode};return e&&t.address_1===e.label&&(t.address_1=""),null!=b.address_1&&(t.address_1=b.address_1),null!=b.postcode&&(t.postcode=b.postcode),t}),[T,b,e]),I=(0,r.useCallback)(((e,t)=>{B[e]=t,B.pickup_location||(B.pickup_location_customer_number=""),(0,a.dispatch)(p.CHECKOUT_STORE_KEY).__internalSetExtensionData("woocommerce-shiptastic",B)}),[B]);(0,r.useEffect)((()=>{L&&Y(O)&&(I("pickup_location",O),I("pickup_location_customer_number",x))}),[O]),(0,r.useEffect)((()=>{if(i((()=>!0)),B.pickup_location){const e=Y(B.pickup_location);if(e){t((()=>e));const c={...T};Object.keys(e.address_replacements).forEach((t=>{const n=e.address_replacements[t];n&&(c[t]=n)})),c!==T&&(A(T),D({shipping_address:c},!1))}else t((()=>null))}else t((()=>null))}),[B.pickup_location]),(0,r.useEffect)((()=>{const e=Y(B.pickup_location);L&&e||B.pickup_location&&(I("pickup_location",""),(0,a.dispatch)("core/notices").createNotice("warning",(0,s._x)("Your pickup location chosen is not available any longer. Please review your shipping address.","shipments","shiptastic-for-woocommerce"),{id:"wc-shiptastic-pickup-location-missing",context:"wc/checkout/shipping-address"}))}),[L]);const V=(0,r.useCallback)((e=>{const t={address:e,provider:m};l()({path:"/wc/store/v1/cart/search-pickup-locations",method:"POST",data:t,cache:"no-store",parse:!1}).then((e=>{l().setNonce(e.headers),e.json().then((function(e){v(e.pickup_locations),d(!1)}))})).catch((e=>{}))}),[m,v,d]),W=function(e,t,c){var o=this,i=(0,n.useRef)(null),r=(0,n.useRef)(0),a=(0,n.useRef)(null),s=(0,n.useRef)([]),u=(0,n.useRef)(),l=(0,n.useRef)(),p=(0,n.useRef)(e),d=(0,n.useRef)(!0);(0,n.useEffect)((function(){p.current=e}),[e]);var m=!t&&0!==t&&"undefined"!=typeof window;if("function"!=typeof e)throw new TypeError("Expected a function");t=+t||0;var h=!!(c=c||{}).leading,f=!("trailing"in c)||!!c.trailing,k="maxWait"in c,_=k?Math.max(+c.maxWait||0,t):null;(0,n.useEffect)((function(){return d.current=!0,function(){d.current=!1}}),[]);var g=(0,n.useMemo)((function(){var e=function(e){var t=s.current,c=u.current;return s.current=u.current=null,r.current=e,l.current=p.current.apply(c,t)},c=function(e,t){m&&cancelAnimationFrame(a.current),a.current=m?requestAnimationFrame(e):setTimeout(e,t)},n=function(e){if(!d.current)return!1;var c=e-i.current;return!i.current||c>=t||c<0||k&&e-r.current>=_},g=function(t){return a.current=null,f&&s.current?e(t):(s.current=u.current=null,l.current)},w=function e(){var o=Date.now();if(n(o))return g(o);if(d.current){var a=t-(o-i.current),s=k?Math.min(a,_-(o-r.current)):a;c(e,s)}},v=function(){var p=Date.now(),m=n(p);if(s.current=[].slice.call(arguments),u.current=o,i.current=p,m){if(!a.current&&d.current)return r.current=i.current,c(w,t),h?e(i.current):l.current;if(k)return c(w,t),e(i.current)}return a.current||c(w,t),l.current};return v.cancel=function(){a.current&&(m?cancelAnimationFrame(a.current):clearTimeout(a.current)),r.current=0,s.current=i.current=u.current=a.current=null},v.isPending=function(){return!!a.current},v.flush=function(){return a.current?g(Date.now()):l.current},v}),[h,k,t,_,f,m]);return g}((e=>{V(e)}),1e3),q=(0,r.useCallback)((e=>{y((t=>{let c={...t,...e};return null==c.address_1&&(c.address_1=T.address_1),null==c.postcode&&(c.postcode=T.postcode),c}))}),[y,b,T]);(0,r.useEffect)((()=>{if(M&&b.postcode){d(!0);const e={...b,country:T.country,city:T.city,state:T.state};W(e)}}),[M,T,b,d]);const H=(0,r.useCallback)((()=>{I("pickup_location",""),(0,a.dispatch)("core/notices").createNotice("warning",(0,s._x)("Please review your shipping address.","shipments","shiptastic-for-woocommerce"),{id:"wc-shiptastic-review-shipping-address",context:"wc/checkout/shipping-address"})}),[K,T,B]),J=(0,r.useCallback)((e=>{if(K.hasOwnProperty(e)){I("pickup_location",e),y({address_1:""}),R(!1);const{removeNotice:t}=(0,a.dispatch)("core/notices");t("wc-shiptastic-review-shipping-address","wc/checkout/shipping-address"),t("wc-shiptastic-pickup-location-missing","wc/checkout/shipping-address")}else e?I("pickup_location",""):H()}),[K,y,T,B]),z=(0,r.useCallback)((e=>{I("pickup_location_customer_number",e)}),[B]);return(0,r.useEffect)((()=>{const t=(0,f.getSelectedShippingProviders)(E),c=Object.keys(t).length>0?t[0]:"";c!==m&&_((t=>(""!==t&&c!==t&&(v(null),e&&H()),c)))}),[E]),(0,r.useRef)(null),(0,n.createElement)(o.ExperimentalOrderShippingPackages,null,(0,n.createElement)(g,{pickupLocationOptions:F,getPickupLocationByCode:Y,isAvailable:M,isSearching:u,onRemovePickupLocation:H,currentPickupLocation:e,onChangePickupLocation:J,onChangePickupLocationSearch:q,pickupLocationSearchAddress:U,onChangePickupLocationCustomerNumber:z,currentPickupLocationCustomerNumber:e?B.pickup_location_customer_number:""}),(0,n.createElement)(k,{currentPickupLocation:e,shippingAddress:T}))},scope:"woocommerce-checkout"}),window.lodash,(0,i.registerPlugin)("woocommerce-shiptastic-set-payment-method",{render:()=>{const{currentPaymentMethod:e}=(0,a.useSelect)((e=>({currentPaymentMethod:e(p.PAYMENT_STORE_KEY).getActivePaymentMethod()})));return(0,r.useEffect)((()=>{e&&(0,o.extensionCartUpdate)({namespace:"woocommerce-shiptastic-set-payment-method",data:{active_method:e}})}),[e]),null},scope:"woocommerce-checkout"})},184:(e,t)=>{var c;!function(){"use strict";var n={}.hasOwnProperty;function o(){for(var e=[],t=0;t<arguments.length;t++){var c=arguments[t];if(c){var i=typeof c;if("string"===i||"number"===i)e.push(c);else if(Array.isArray(c)){if(c.length){var r=o.apply(null,c);r&&e.push(r)}}else if("object"===i)if(c.toString===Object.prototype.toString)for(var a in c)n.call(c,a)&&c[a]&&e.push(a);else e.push(c.toString())}}return e.join(" ")}e.exports?(o.default=o,e.exports=o):void 0===(c=function(){return o}.apply(t,[]))||(e.exports=c)}()}},c={};function n(e){var o=c[e];if(void 0!==o)return o.exports;var i=c[e]={exports:{}};return t[e](i,i.exports,n),i.exports}n.m=t,e=[],n.O=(t,c,o,i)=>{if(!c){var r=1/0;for(l=0;l<e.length;l++){c=e[l][0],o=e[l][1],i=e[l][2];for(var a=!0,s=0;s<c.length;s++)(!1&i||r>=i)&&Object.keys(n.O).every((e=>n.O[e](c[s])))?c.splice(s--,1):(a=!1,i<r&&(r=i));if(a){e.splice(l--,1);var u=o();void 0!==u&&(t=u)}}return t}i=i||0;for(var l=e.length;l>0&&e[l-1][2]>i;l--)e[l]=e[l-1];e[l]=[c,o,i]},n.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return n.d(t,{a:t}),t},n.d=(e,t)=>{for(var c in t)n.o(t,c)&&!n.o(e,c)&&Object.defineProperty(e,c,{enumerable:!0,get:t[c]})},n.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t),n.r=e=>{"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(e,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(e,"__esModule",{value:!0})},(()=>{var e={157:0,352:0};n.O.j=t=>0===e[t];var t=(t,c)=>{var o,i,r=c[0],a=c[1],s=c[2],u=0;if(r.some((t=>0!==e[t]))){for(o in a)n.o(a,o)&&(n.m[o]=a[o]);if(s)var l=s(n)}for(t&&t(c);u<r.length;u++)i=r[u],n.o(e,i)&&e[i]&&e[i][0](),e[i]=0;return n.O(l)},c=self.webpackWcShiptasticBlocksJsonp=self.webpackWcShiptasticBlocksJsonp||[];c.forEach(t.bind(null,0)),c.push=t.bind(null,c.push.bind(c))})();var o=n.O(void 0,[352],(()=>n(593)));o=n.O(o),(window.wcShiptastic=window.wcShiptastic||{})["checkout-pickup-location-select"]=o})();