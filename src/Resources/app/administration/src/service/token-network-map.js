/**
 * Static token + network lookups to humanize the raw settle-time facts (atomic amount, token
 * contract, CAIP-2 network). Every lookup fails soft: unknown -> null, and the card falls back to
 * the raw value. Extend the tables as new tokens/chains appear.
 */

/**
 * Token contract address (lowercased) -> { symbol, decimals }. Keyed by address (same symbol,
 * different contract per chain); decimals aren't in the x402 payload, hence this map.
 *
 * Testnet addresses are authoritative (from a real Prism offer). Mainnet ones are omitted on
 * purpose: a missing address fails soft, but a wrong address->symbol would show false info.
 */
export const TOKENS = {
    // FDUSD — First Digital USD (18 dp). Same test contract reused across several testnets.
    '0xab27f55db008704ed8098f0dfbcf5e1aa387b9d9': { symbol: 'FDUSD', decimals: 18 },

    // EURC (6 dp)
    '0x808456652fdb597867f38412077a9182bf77359f': { symbol: 'EURC', decimals: 6 }, // Base Sepolia
    '0x08210f9170f89ab7658f0b5e3ff39b0e03c594d4': { symbol: 'EURC', decimals: 6 }, // Ethereum Sepolia

    // USDC (6 dp)
    '0x036cbd53842c5426634e7929541ec2318f3dcf7e': { symbol: 'USDC', decimals: 6 }, // Base Sepolia
    '0x1c7d4b196cb0c7b01d743fbc6116a902379c7238': { symbol: 'USDC', decimals: 6 }, // Ethereum Sepolia
    '0x75faf114eafb1bdbe2f0316df893fd58ce46aa4d': { symbol: 'USDC', decimals: 6 }, // Arbitrum Sepolia

    // --- mainnet canonical addresses: add here once verified (fail-soft until then) ---
};

/**
 * CAIP-2 network id -> friendly name. Mainnets + their testnets for the chains Prism settles on.
 */
export const NETWORKS = {
    'eip155:1': 'Ethereum',
    'eip155:11155111': 'Ethereum Sepolia',
    'eip155:56': 'BNB Smart Chain',
    'eip155:97': 'BNB Smart Chain Testnet',
    'eip155:8453': 'Base',
    'eip155:84532': 'Base Sepolia',
    'eip155:42161': 'Arbitrum One',
    'eip155:421614': 'Arbitrum Sepolia',
};

/**
 * @param {?string} address token contract address (any case)
 * @returns {?{symbol: string, decimals: number}} null when unknown (caller shows raw + report note)
 */
export function resolveToken(address) {
    if (typeof address !== 'string') {
        return null;
    }

    return TOKENS[address.toLowerCase()] ?? null;
}

/**
 * @param {?string} caip2 network id, e.g. "eip155:97"
 * @returns {?string} friendly name, or null when unknown
 */
export function resolveNetwork(caip2) {
    if (typeof caip2 !== 'string') {
        return null;
    }

    return NETWORKS[caip2] ?? null;
}

/**
 * Humanize an atomic on-chain amount into a decimal string, using integer math (BigInt) so we
 * never introduce floating-point error on token amounts.
 *
 * @param {?(string|number|bigint)} atomic raw base-unit amount, e.g. "5023284756513709904"
 * @param {?number} decimals token decimals, e.g. 18
 * @param {number} fractionDigits digits to show after the point (rounded half-up)
 * @returns {?string} e.g. "5.02", or null when it cannot be humanized (unknown decimals / bad input)
 */
export function formatAmount(atomic, decimals, fractionDigits = 2) {
    if (atomic === null || atomic === undefined || typeof decimals !== 'number') {
        return null;
    }

    try {
        const raw = String(atomic).trim();
        const negative = raw.startsWith('-');
        const digits = negative ? raw.slice(1) : raw;
        if (!/^\d+$/.test(digits)) {
            return null;
        }

        const value = BigInt(digits);
        const base = 10n ** BigInt(decimals);
        const whole = value / base;
        const frac = value % base;

        const scale = 10n ** BigInt(fractionDigits);
        const numerator = frac * scale;
        let rounded = numerator / base; // floor
        if ((numerator % base) * 2n >= base) {
            rounded += 1n; // round half-up
        }

        let wholeAdjusted = whole;
        if (rounded >= scale) {
            wholeAdjusted += 1n;
            rounded -= scale;
        }

        const fracStr = fractionDigits > 0
            ? `.${rounded.toString().padStart(fractionDigits, '0')}`
            : '';

        return `${negative ? '-' : ''}${wholeAdjusted.toString()}${fracStr}`;
    } catch (e) {
        return null;
    }
}

/**
 * Derive the Prism app "sales" URL from the gateway URL by replacing the leftmost label with
 * "apps" (https://prism-gw.fd.xyz -> https://apps.fd.xyz/prism/sales); same shape in test. Returns
 * null when the host doesn't fit (fails soft). Identifiers are appended as optional query params so
 * /prism/sales stays valid on its own.
 *
 * @param {?string} gatewayUrl e.g. "https://prism-gw.fd.xyz"
 * @param {{tx?: ?string, network?: ?string, source?: ?string}} [params] optional query identifiers
 * @returns {?string} e.g. "https://apps.fd.xyz/prism/sales?tx=0x…&network=eip155:97&source=…", or null
 */
export function derivePrismSalesUrl(gatewayUrl, params = {}) {
    if (typeof gatewayUrl !== 'string' || gatewayUrl.trim() === '') {
        return null;
    }

    try {
        const parsed = new URL(gatewayUrl.trim());
        const labels = parsed.hostname.split('.');
        if (labels.length < 3) {
            // Need at least service + base (e.g. prism-gw.fd.xyz -> ['prism-gw','fd','xyz']).
            return null;
        }

        const base = labels.slice(1).join('.');
        const salesUrl = `${parsed.protocol}//apps.${base}/prism/sales`;

        const query = new URLSearchParams();
        if (params.tx) {
            query.set('tx', params.tx);
        }
        if (params.network) {
            query.set('network', params.network);
        }
        if (params.source) {
            query.set('source', params.source);
        }

        const qs = query.toString();
        return qs ? `${salesUrl}?${qs}` : salesUrl;
    } catch (e) {
        return null;
    }
}
