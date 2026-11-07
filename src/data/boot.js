/**
 * Server-provided boot data (see Admin\AdminPage::bootData()).
 */
const boot = window.trustorderAdmin || {};

export const NAMESPACE = boot.namespace || 'trustorder/v1';
export const VERSION = boot.version || '';
export const SHOP_COUNTRY = boot.shopCountry || '';
export const COUNTRIES = boot.countries || {};
