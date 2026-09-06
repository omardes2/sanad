<?php

declare(strict_types=1);

namespace App\Support\Audit;

/**
 * Known audit action names (dot-namespaced `<area>.<event>`). Free-form
 * actions are allowed, but every action the platform itself emits is listed
 * here so the audit page and tests can rely on stable names.
 */
final class AuditActions
{
    public const RbacBootstrapApplied = 'rbac.bootstrap_applied';

    public const RbacRoleAssigned = 'rbac.role_assigned';

    public const RbacRoleRevoked = 'rbac.role_revoked';

    public const SettingsUpdated = 'settings.updated';

    public const SettingsReset = 'settings.reset';

    public const AiProviderUpdated = 'ai.provider.updated';

    public const AiModelCreated = 'ai.model.created';

    public const AiModelUpdated = 'ai.model.updated';

    public const AiModelDeleted = 'ai.model.deleted';

    public const AiPricePublished = 'ai.price.published';

    public const AiCatalogBootstrapApplied = 'ai.catalog.bootstrap_applied';

    public const AiCredentialCreated = 'ai.credentials.created';

    public const AiCredentialActivated = 'ai.credentials.activated';

    /** Super Admin force path: activated WITHOUT a successful auth verification. */
    public const AiCredentialActivatedUnverified = 'ai.credentials.activated_unverified';

    public const AiCredentialRevoked = 'ai.credentials.revoked';

    public const AiCredentialResolveFailed = 'ai.credentials.resolve_failed';

    public const AiCredentialKeyRotated = 'ai.credentials.key_rotated';

    public const AiProviderHealthChecked = 'ai.provider.health_checked';

    public const AiCatalogSourceChanged = 'ai.catalog.source_changed';

    public const AiRoutingModeChanged = 'ai.routing.mode_changed';

    public const AiRoutingPrimaryChanged = 'ai.routing.primary_changed';

    /** System entry (rate-limited): db mode had no usable primary, AI_PROVIDER used. */
    public const AiRoutingEnvFallbackUsed = 'ai.routing.env_fallback_used';

    /** A plan was created (financial fields recorded: price, currency, billing_period). */
    public const PlanCreated = 'plan.created';

    /** price / currency / billing_period of a plan changed (from → to, atomic with the save). */
    public const PlanFinancialsUpdated = 'plan.financials_updated';

    /** Console entry: today's MRR snapshot rows were captured (counts only). */
    public const FinanceMrrSnapshotCaptured = 'finance.mrr_snapshot_captured';

    /** A subscription transitioned (event_type in changes); written with the subscription_events row. */
    public const SubscriptionTransitioned = 'subscription.transitioned';

    /** A plan price version was opened (and the previous one closed) — atomic with the plan save. */
    public const PlanPriceVersioned = 'plan.price_versioned';

    /** Console entry: the financial history baseline was captured (counts only). */
    public const FinanceHistoryBaselineApplied = 'finance.history_baseline_applied';

    /** A manual customer payment was recorded (created → succeeded) — atomic with the rows. */
    public const PaymentRecorded = 'payment.recorded';

    /** A payment lifecycle event was appended and the projection updated. */
    public const PaymentTransitioned = 'payment.transitioned';

    /** A (partial) refund was recorded against a succeeded payment. */
    public const PaymentRefunded = 'payment.refunded';

    /** Collected cash was attributed to a subscription service period. */
    public const PaymentAllocated = 'payment.allocated';

    /** A refund was attributed to a payment allocation. */
    public const RefundAllocated = 'refund.allocated';

    /** Phase E2 — a supplier invoice (evidence) was recorded as a draft. */
    public const CostInvoiceRecorded = 'cost_invoice.recorded';

    /** Phase E2 — a signed line was added to a draft invoice. */
    public const CostInvoiceLineAdded = 'cost_invoice.line_added';

    /** Phase E2 — the invoice lifecycle moved (confirmed / voided / superseded). */
    public const CostInvoiceTransitioned = 'cost_invoice.transitioned';

    /** Phase E2 — a cost reconciliation became the scope's current one. */
    public const CostReconciled = 'cost.reconciled';

    /** Phase E2 — a signed adjustment was appended to the current reconciliation. */
    public const CostAdjusted = 'cost.adjusted';

    /** Phase E3 — a canonical FX pair was created with its official orientation. */
    public const FxPairCreated = 'fx.pair_created';

    /** Phase E3 — a manual quote (or a revision of one) was recorded for a pair and date. */
    public const FxRateRecorded = 'fx.rate_recorded';

    /** Phase E3 — a frozen reporting conversion (or a revision) was recorded. */
    public const FxConverted = 'fx.converted';

    /** Phase E3 — the reporting currency setting changed (typed confirmation). */
    public const FinanceReportingCurrencyChanged = 'finance.reporting_currency_changed';
}
