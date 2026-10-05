// src/lib/factor.ts
function getDecimalPlaces(value) {
  if (!Number.isFinite(value))
    return 0;
  const str = String(value);
  const dotIndex = str.indexOf(".");
  if (dotIndex === -1)
    return 0;
  const expIndex = str.indexOf("e-");
  if (expIndex !== -1) {
    const mantissaDecimals = str.substring(dotIndex + 1, expIndex).length;
    const exp = parseInt(str.substring(expIndex + 2), 10);
    return mantissaDecimals + exp;
  }
  return str.length - dotIndex - 1;
}
var MAX_DECIMAL_PLACES = 10;
var DEFAULT_FACTOR = 1e5;
function computeFactor(values) {
  let maxDecimals = 0;
  for (const v of values) {
    const d = getDecimalPlaces(v);
    if (d > maxDecimals)
      maxDecimals = d;
  }
  if (maxDecimals === 0)
    return 1;
  if (maxDecimals > MAX_DECIMAL_PLACES)
    maxDecimals = MAX_DECIMAL_PLACES;
  return Math.pow(10, maxDecimals);
}
function factoredInteger(value, factor = DEFAULT_FACTOR) {
  return Math.round(value * factor);
}
function toOriginal(value, factor = DEFAULT_FACTOR) {
  if (factor === 1)
    return value;
  const decimalPlaces = Math.round(Math.log10(factor));
  return Number((value / factor).toFixed(decimalPlaces));
}

export { computeFactor, factoredInteger, toOriginal };
