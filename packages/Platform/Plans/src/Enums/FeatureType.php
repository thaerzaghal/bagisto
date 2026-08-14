<?php

declare(strict_types=1);

namespace Platform\Plans\Enums;

/**
 * TASK-ARCH-008. The three entitlement semantics a `plan_features` row can
 * have, matching docs/architecture/feature-limits.md's generic key-value
 * design: Boolean rows use `value` as 0/1, Numeric rows use `value` as the
 * integer cap, Unlimited rows ignore `value` entirely (no cap at all - a
 * distinct type, not "Numeric with a null value", so a feature can move
 * between "capped" and "uncapped" without the storage shape changing).
 */
enum FeatureType: string
{
    case Boolean = 'boolean';
    case Numeric = 'numeric';
    case Unlimited = 'unlimited';
}
