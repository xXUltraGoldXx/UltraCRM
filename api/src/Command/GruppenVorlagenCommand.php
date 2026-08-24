<?php

namespace App\Command;

use App\Entity\Tenant;
use App\Service\Standardgruppen;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfills the default permission-group templates for tenants that
 * already existed before templates were introduced.
 *
 * New tenants get them automatically (see TenantStandardgruppenListener);
 * existing ones need this one-time run. Safe to run multiple times.
 */
#[AsCommand(
    name: 'app:gruppen:vorlagen',
    description: 'Legt fehlende Standard-Berechtigungsgruppen je Mandant an.',
)]
final class GruppenVorlagenCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Standardgruppen $standardgruppen,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stil = new SymfonyStyle($input, $output);

        // This command runs without a logged-in user; the tenant filter
        // would otherwise default to closed and find no tenants at all.
        if ($this->em->getFilters()->isEnabled('tenant_filter')) {
            $this->em->getFilters()->disable('tenant_filter');
        }

        $gesamt = 0;
        foreach ($this->em->getRepository(Tenant::class)->findAll() as $mandant) {
            $angelegt = $this->standardgruppen->anlegen($mandant);
            if ($angelegt !== []) {
                $stil->writeln(sprintf('%s: %s', $mandant->getName(), implode(', ', $angelegt)));
                $gesamt += count($angelegt);
            }
        }

        $this->em->flush();

        $stil->success($gesamt === 0
            ? 'Nichts nachzutragen — alle Mandanten haben ihre Vorlagen.'
            : sprintf('%d Gruppe(n) angelegt.', $gesamt));

        return Command::SUCCESS;
    }
}
