/**
 * C3 — Single source of truth: Stripe priceId -> internal tier.
 *
 * Shared by create-checkout.js (sets metadata) and stripe-webhook.js (provisions).
 * This MUST mirror the ALLOWED_PRICE_IDS allow-list in create-checkout.js exactly.
 * Any priceId NOT listed here fails closed (deny provisioning + alert) — never a
 * silently-granted tier.
 *
 * Currency note: there are NO per-currency priceIds. geo-currency only sets a display
 * currency; non-GBP checkouts use inline price_data DERIVED from one of these priceIds,
 * so the line item on a non-GBP order carries an ephemeral price id. The authoritative
 * "price actually paid" for those is the priceId recorded server-side in metadata.price_id.
 */
'use strict';

const PRICE_TO_TIER = {
  // Course — one-time
  'price_1T0RzZAARZOPki7ntFFvn9sT': 'course_super_early',
  'price_1T0MrLAARZOPki7nfXckkNqh': 'course_founding',
  'price_1T0MrKAARZOPki7nJre4xDFt': 'course_standard',
  // Course — installments
  'price_1T0RzZAARZOPki7nqvhHqbht': 'course_super_early',
  'price_1T0RzaAARZOPki7nkFk4aiEm': 'course_founding',
  'price_1T0MrLAARZOPki7ncedlhLG6': 'course_standard',
  // Signature — one-time
  'price_1T0RzZAARZOPki7nq98AvjGG': 'signature_super_early',
  'price_1T0MrMAARZOPki7n9cinYvKE': 'signature_founding',
  'price_1T0MrMAARZOPki7nDfv04ySN': 'signature_standard',
  // Signature — installments
  'price_1T0RzaAARZOPki7ntdAHvkXt': 'signature_super_early',
  'price_1T0RzaAARZOPki7nTEd3sEln': 'signature_founding',
  'price_1T0MrNAARZOPki7nZzc5Tjoo': 'signature_standard',
  // Subscriptions
  'price_1T0MrIAARZOPki7nIC4fsZ8h': 'launchpad',
  'price_1T0MrJAARZOPki7nT8Lwbckc': 'minimum',
  // Consumer (personal report) — the provisioning-critical tiers
  'price_1T2KlHAARZOPki7nx77wtLhj': 'consumer_single',
  'price_1T3944AARZOPki7nWYs5ZCeK': 'consumer_annual',
};

// Only these tiers trigger WordPress consumer provisioning (role + credits).
const CONSUMER_TIERS = new Set(['consumer_single', 'consumer_annual']);

/**
 * Pick the authoritative paid priceId: prefer a STORED price id that actually appears on
 * the Stripe line items (GBP path); otherwise fall back to the server-recorded price_id
 * (non-GBP inline prices don't carry the stored id). Never trusts a client-set field.
 */
function resolvePaidPriceId(lineItems, recordedPriceId) {
  const items = (lineItems && lineItems.data) || [];
  for (const it of items) {
    const pid = it && it.price && it.price.id;
    if (pid && Object.prototype.hasOwnProperty.call(PRICE_TO_TIER, pid)) {
      return pid; // a real, allow-listed price was charged (independent of metadata)
    }
  }
  return recordedPriceId || '';
}

/**
 * Derive the provisioning decision from the ACTUAL price paid — never from metadata.tier.
 * @param {{lineItems?: object, metadata?: object}} args
 * @returns {{paidPriceId:string, tier:(string|null), isConsumer:boolean,
 *            provision:boolean, mismatch:boolean, reason:string}}
 *   reason: 'ok' | 'consumer_tier_mismatch' | 'non_consumer' | 'unknown_price_id'
 */
function deriveProvisioning(args) {
  const metadata = (args && args.metadata) || {};
  const paidPriceId = resolvePaidPriceId(args && args.lineItems, metadata.price_id);
  const tier = Object.prototype.hasOwnProperty.call(PRICE_TO_TIER, paidPriceId)
    ? PRICE_TO_TIER[paidPriceId]
    : null;

  if (!tier) {
    // Fail closed: an unknown/unmapped price must never be silently granted a tier.
    return { paidPriceId, tier: null, isConsumer: false, provision: false, mismatch: false, reason: 'unknown_price_id' };
  }

  const isConsumer = CONSUMER_TIERS.has(tier);
  // metadata.tier is display-only; the PAID tier is authoritative. A mismatch is surfaced
  // for alerting but does not block provisioning of the correct (paid) tier.
  const mismatch = Boolean(metadata.tier && metadata.tier !== tier);

  return {
    paidPriceId,
    tier,
    isConsumer,
    provision: isConsumer,
    mismatch,
    reason: isConsumer ? (mismatch ? 'consumer_tier_mismatch' : 'ok') : 'non_consumer',
  };
}

module.exports = { PRICE_TO_TIER, CONSUMER_TIERS, resolvePaidPriceId, deriveProvisioning };
