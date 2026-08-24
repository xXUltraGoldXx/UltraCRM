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
 * Traegt die Standardvorlagen bei Mandanten nach, die es schon vor A14 gab.
 *
 * Neue Mandanten bekommen sie automatisch (TenantStandardgruppenListener) —
 * bestehende brauchen diesen einen Aufruf. Mehrfach ausfuehrbar.
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

        // Der Befehl laeuft ohne angemeldeten Benutzer; der Mandantenfilter
        // stuende sonst auf "zu" und faende keinen einzigen Mandanten.
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
