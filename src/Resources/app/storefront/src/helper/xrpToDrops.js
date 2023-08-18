import BigNumber from "../node_modules/bignumber.js";

const DROPS_PER_XRP = 1000000.0
const MAX_FRACTION_LENGTH = 6
const BASE_TEN = 10
const SANITY_CHECK = /^-?[0-9.]+$/u

function xrpToDrops(xrpToConvert) {
    const xrp = new BigNumber(xrpToConvert).toString(BASE_TEN)

    // check that the value is valid and actually a number
    if (typeof xrpToConvert === 'string' && xrp === 'NaN') {
        throw new Error(
            `xrpToDrops: invalid value '${xrpToConvert}', should be a BigNumber or string-encoded number.`,
        )
    }

    /*
     * This should never happen; the value has already been
     * validated above. This just ensures BigNumber did not do
     * something unexpected.
     */
    if (!SANITY_CHECK.exec(xrp)) {
        throw new Error(
            `xrpToDrops: failed sanity check - value '${xrp}', does not match (^-?[0-9.]+$).`,
        )
    }

    const components = xrp.split('.')
    if (components.length > 2) {
        throw new Error(
            `xrpToDrops: failed sanity check - value '${xrp}' has too many decimal points.`,
        )
    }

    const fraction = components[1] || '0'
    if (fraction.length > MAX_FRACTION_LENGTH) {
        throw new Error(
            `xrpToDrops: value '${xrp}' has too many decimal places.`,
        )
    }

    return new BigNumber(xrp)
        .times(DROPS_PER_XRP)
        .integerValue(BigNumber.ROUND_FLOOR)
        .toString(BASE_TEN)
}

export default xrpToDrops