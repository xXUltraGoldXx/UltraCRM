<?php

namespace App\Entity;

/**
 * Markiert Entities, deren Daten einem Mandanten gehoeren. Der Doctrine-
 * Filter tenant_filter haengt an ALLE Abfragen solcher Entities automatisch
 * die Mandanten-Bedingung an — Isolation haengt damit nicht an von Hand
 * geschriebenen WHERE-Klauseln.
 */
interface TenantOwnedInterface
{
    public function getTenant(): ?Tenant;

    public function setTenant(?Tenant $tenant): static;
}
