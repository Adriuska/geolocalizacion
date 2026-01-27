<?php

namespace App\Command;

use App\Entity\Invitation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup-old-invitations',
    description: 'Eliminar invitaciones antiguas rechazadas o aceptadas (defecto: 7 días)',
)]
class CleanupOldInvitationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_OPTIONAL,
            'Días de antigüedad para eliminar invitaciones procesadas (defecto: 7)',
            7
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int)$input->getOption('days');

        $io->title('Limpieza de invitaciones antiguas');
        $io->info(sprintf('Eliminando invitaciones procesadas con más de %d días...', $days));

        $dateLimit = new \DateTime(sprintf('-%d days', $days));

        // Eliminar invitaciones aceptadas o rechazadas antiguas
        $deleted = $this->entityManager->getRepository(Invitation::class)
            ->createQueryBuilder('i')
            ->delete()
            ->where('i.status != :pending')
            ->andWhere('i.createdAt < :dateLimit')
            ->setParameter('pending', 'pending')
            ->setParameter('dateLimit', $dateLimit)
            ->getQuery()
            ->execute();

        if ($deleted > 0) {
            $io->success(sprintf('Se eliminaron %d invitaciones antiguas.', $deleted));
        } else {
            $io->info('No hay invitaciones antiguas para eliminar.');
        }

        return Command::SUCCESS;
    }
}
