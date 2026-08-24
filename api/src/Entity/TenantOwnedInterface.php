<?php

namespace App\Entity;

/**
 * Marks entities whose data belongs to a tenant. The Doctrine filter
 * tenant_filter automatically appends the tenant condition to ALL queries
 * against such entities — isolation therefore does not depend on
 * hand-written WHERE clauses.
 */
interface TenantOwnedInterface
{
    public function getTenant(): ?Tenant;

    public function setTenant(?Tenant $tenant): static;
}
