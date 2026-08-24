<?php

namespace App\Command;

use App\Entity\Deal;
use App\Entity\Pipeline;
use App\Entity\Stage;
use App\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zieht den Umstieg auf konfigurierbare Phasen (A5) nach.
 *
 * Auf dem Produktivserver lief das am 24.08. von Hand. Damit war es genau
 * einmal richtig — ein zweiter Server, ein frischer Checkout oder eine
 * Wiederherstellung aus einem Backup von vor A5 haette lauter Vorgaenge ohne
 * Phase, und weil die Phase am Vorgang Pflicht ist, liesse sich an diesen
 * Vorgaengen anschliessend gar nichts mehr aendern (Review-Befund,
 * Schwere 50).
 *
 * Der Befehl ist mehrfach ausfuehrbar: er legt nur an, was fehlt.
 */
#[AsCommand(
    name: 'app:phasen:nachziehen',
    description: 'Legt fehlende Startpipelines an und ordnet Vorgaenge ohne Phase zu.',
)]
final class PhasenNachziehenCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'probe',
            null,
            InputOption::VALUE_NONE,
            'Nur zeigen, was geschehen wuerde, ohne zu speichern.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stil = new SymfonyStyle($input, $output);
        $probe = (bool) $input->getOption('probe');

        // Der Mandantenfilter wuerde hier im Weg stehen: der Befehl laeuft
        // ohne angemeldeten Benutzer und muss ausdruecklich alle Mandanten
        // sehen.
        if ($this->em->getFilters()->isEnabled('tenant_filter')) {
            $this->em->getFilters()->disable('tenant_filter');
        }

        $angelegt = 0;
        foreach ($this->em->getRepository(Tenant::class)->findAll() as $mandant) {
            $vorhanden = (int) $this->em->getRepository(Pipeline::class)
                ->count(['tenant' => $mandant]);
            if ($vorhanden > 0) {
                continue;
            }

            $stil->writeln(sprintf(
                'Mandant "%s" hat keine Pipeline — Startpipeline wird angelegt.',
                $mandant->getName()
            ));
            ++$angelegt;

            if ($probe) {
                continue;
            }

            $pipeline = (new Pipeline())->setName('Vertrieb')->setPosition(0);
            $pipeline->setTenant($mandant);
            $this->em->persist($pipeline);

            $position = 0;
            foreach (Deal::START_PHASEN as $name => $art) {
                $phase = (new Stage())->setName($name)->setArt($art)->setPosition($position++);
                $phase->setPipeline($pipeline);
                $phase->setTenant($mandant);
                $this->em->persist($phase);
            }
        }

        if (!$probe && $angelegt > 0) {
            $this->em->flush();
        }

        $zugeordnet = $this->vorgaengeZuordnen($stil, $probe);

        if ($angelegt === 0 && $zugeordnet === 0) {
            $stil->success('Nichts nachzuziehen — alle Mandanten haben Phasen, alle Vorgaenge haengen daran.');

            return Command::SUCCESS;
        }

        $stil->success(sprintf(
            '%s: %d Startpipeline(s), %d Vorgang/Vorgaenge zugeordnet.',
            $probe ? 'Probe (nichts gespeichert)' : 'Erledigt',
            $angelegt,
            $zugeordnet
        ));

        return Command::SUCCESS;
    }

    /**
     * Ordnet Vorgaenge zu, die noch keine Phase haben. Existiert die alte
     * Textspalte noch (Stand vor der Umstellung), wird ihr Wert auf den
     * Phasennamen abgebildet; sonst landet der Vorgang in der ersten Phase
     * seines Mandanten.
     */
    private function vorgaengeZuordnen(SymfonyStyle $stil, bool $probe): int
    {
        $alteSpalte = $this->alteSpalteVorhanden();
        $offen = 0;

        foreach ($this->em->getRepository(Deal::class)->findBy(['stage' => null]) as $vorgang) {
            /** @var Stage[] $phasen */
            $phasen = $this->em->getRepository(Stage::class)->findBy(
                ['tenant' => $vorgang->getTenant()],
                ['position' => 'ASC']
            );

            if ($phasen === []) {
                $stil->warning(sprintf(
                    'Vorgang %d hat keinen Mandanten mit Phasen — bitte von Hand pruefen.',
                    $vorgang->getId()
                ));
                continue;
            }

            $ziel = $phasen[0];

            if ($alteSpalte) {
                $alt = $this->db->fetchOne('SELECT stage FROM deal WHERE id = ?', [$vorgang->getId()]);
                foreach ($phasen as $phase) {
                    if (is_string($alt) && mb_strtolower($phase->getName()) === mb_strtolower($alt)) {
                        $ziel = $phase;
                        break;
                    }
                }
            }

            $stil->writeln(sprintf(
                'Vorgang %d ("%s") → Phase "%s"',
                $vorgang->getId(),
                $vorgang->getTitle(),
                $ziel->getName()
            ));
            ++$offen;

            if (!$probe) {
                $vorgang->setStage($ziel);
            }
        }

        if (!$probe && $offen > 0) {
            $this->em->flush();
        }

        return $offen;
    }

    private function alteSpalteVorhanden(): bool
    {
        foreach ($this->db->createSchemaManager()->listTableColumns('deal') as $spalte) {
            if ($spalte->getName() === 'stage') {
                return true;
            }
        }

        return false;
    }
}
