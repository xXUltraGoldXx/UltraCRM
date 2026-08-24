<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/** Common base for public, non-authenticated endpoints. */
abstract class AbstractPublicController extends AbstractController
{
    /**
     * Runs a query without the tenant filter and restores it afterwards.
     *
     * Why this needs its own method: `enable('tenant_filter')` returns a
     * NEW filter instance after a `disable()` — without a parameter.
     * Calling only disable/enable leaves the filter enabled but without a
     * parameter, and the next query on tenant-owned data dies with
     * "Parameter 'tenant_id' does not exist" (HTTP 500). This is exactly
     * what broke lead intake once the mail configuration lookup was
     * added to it.
     *
     * Public endpoints always run unauthenticated, so the correct state
     * afterwards is: filter on, tenant 0 — i.e. nothing visible by
     * default, the same rule as in TenantFilterSubscriber.
     *
     * @template T
     * @param callable(): T $abfrage
     * @return T
     */
    protected function ohneMandantenfilter(EntityManagerInterface $em, callable $abfrage): mixed
    {
        $filters = $em->getFilters();
        $warAn = $filters->isEnabled('tenant_filter');

        if ($warAn) {
            $filters->disable('tenant_filter');
        }

        try {
            return $abfrage();
        } finally {
            if ($warAn) {
                $filters->enable('tenant_filter')->setParameter('tenant_id', '0');
            }
        }
    }
}
