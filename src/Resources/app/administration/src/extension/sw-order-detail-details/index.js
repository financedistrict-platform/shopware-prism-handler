import template from './sw-order-detail-details.html.twig';

// Inject the Prism merchant card into the order Detail tab, directly after the
// native Payment card.
Shopware.Component.override('sw-order-detail-details', {
    template,
});
