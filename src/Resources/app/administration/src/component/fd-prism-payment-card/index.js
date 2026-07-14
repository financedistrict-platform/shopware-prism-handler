import template from './fd-prism-payment-card.html.twig';
import './fd-prism-payment-card.scss';
import {
    resolveToken,
    resolveNetwork,
    formatAmount,
    derivePrismSalesUrl,
} from '../../service/token-network-map';

// Prism merchant card on the order Detail tab: reads raw settle-time facts from our admin endpoint
// and humanizes them via the token/network map, failing soft to raw values.
Shopware.Component.register('fd-prism-payment-card', {
    template,

    props: {
        // Passed from sw-order-detail-details; may be null on first render while the order loads.
        order: {
            type: Object,
            required: false,
            default: null,
        },
    },

    data() {
        return {
            // Raw facts from the endpoint when this is a settled Prism order; null otherwise (card hides).
            settlement: null,
            loadedForOrderId: null,
        };
    },

    computed: {
        orderId() {
            // Prefer the prop; fall back to the order-detail store so the card works even if the
            // parent template scope doesn't expose `order`.
            if (this.order?.id) {
                return this.order.id;
            }

            try {
                return Shopware.Store.get('swOrderDetail')?.order?.id ?? null;
            } catch (e) {
                return null;
            }
        },

        isPrismOrder() {
            return this.settlement !== null;
        },

        token() {
            return this.settlement ? resolveToken(this.settlement.asset) : null;
        },

        networkName() {
            return this.settlement ? resolveNetwork(this.settlement.network) : null;
        },

        // Humanized "5.02" when the token (hence decimals) is known; null otherwise.
        amountDisplay() {
            if (!this.settlement || !this.token) {
                return null;
            }

            return formatAmount(this.settlement.amountAtomic, this.token.decimals);
        },

        // What the hero shows: humanized amount when possible, else the raw atomic value.
        amountText() {
            return this.amountDisplay ?? this.settlement?.amountAtomic ?? '—';
        },

        // Symbol when the token is known, else a shortened contract address.
        symbolText() {
            return this.token?.symbol ?? this.shortAddress(this.settlement?.asset);
        },

        networkText() {
            return this.networkName ?? this.settlement?.network ?? '—';
        },

        paidAtText() {
            const iso = this.settlement?.settledAt;
            if (!iso) {
                return '—';
            }

            const date = new Date(iso);
            if (Number.isNaN(date.getTime())) {
                return iso;
            }

            return new Intl.DateTimeFormat('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            }).format(date);
        },

        prismUrl() {
            return derivePrismSalesUrl(this.settlement?.gatewayUrl, {
                tx: this.settlement?.txHash,
                network: this.settlement?.network,
                source: 'shopware-admin', // origin surface, for Prism-side attribution
            });
        },

        // Drives the "report to Prism" notice: a token or chain we couldn't translate.
        hasUnmapped() {
            if (!this.settlement) {
                return false;
            }

            return this.token === null || this.networkName === null;
        },
    },

    watch: {
        orderId: {
            immediate: true,
            handler(orderId) {
                if (orderId) {
                    this.loadSettlement(orderId);
                }
            },
        },
    },

    methods: {
        async loadSettlement(orderId) {
            if (this.loadedForOrderId === orderId) {
                return;
            }
            this.loadedForOrderId = orderId;

            try {
                const httpClient = Shopware.Application.getContainer('init').httpClient;
                const { getToken } = Shopware.Service('loginService');

                const response = await httpClient.get(
                    `_action/fd-prism-payment/order/${orderId}/settlement`,
                    { headers: { Authorization: `Bearer ${getToken()}` } },
                );

                this.settlement = response.data?.settled ? response.data : null;
            } catch (e) {
                // Never surface an error card — a failed read just hides the card (as if not a
                // Prism order). The native Payment card still shows the order's paid status.
                this.settlement = null;
            }
        },

        shortAddress(address) {
            if (typeof address !== 'string' || address === '') {
                return '—';
            }

            return address.length > 12
                ? `${address.slice(0, 6)}…${address.slice(-4)}`
                : address;
        },
    },
});
