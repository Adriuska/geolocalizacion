<?php

namespace App\Command;

use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-old-messages',
    description: 'Eliminar mensajes antiguos (por defecto más de 30 días)',
)]
class PurgeOldMessagesCommand extends Command
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
            'Días de antigüedad para eliminar mensajes (defecto: 30)',
            30
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int)$input->getOption('days');

        $io->title('Limpieza de mensajes antiguos');
        $io->info(sprintf('Eliminando mensajes con más de %d días de antigüedad...', $days));

        $dateLimit = new \DateTime(sprintf('-%d days', $days));

        // Contar mensajes a eliminar
        $count = $this->entityManager->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt < :dateLimit')
            ->setParameter('dateLimit', $dateLimit)
            ->getQuery()
            ->getSingleScalarResult();

        if ($count == 0) {
            $io->success('No hay mensajes antiguos para eliminar.');
            return Command::SUCCESS;
        }

        $io->text(sprintf('Se encontraron %d mensajes para eliminar.', $count));
        $io->progressStart($count);

        // Eliminar mensajes en lotes para evitar problemas de memoria
        $batchSize = 100;
        $deleted = 0;

        do {
            $messages = $this->entityManager->getRepository(Message::class)
                ->createQueryBuilder('m')
                ->where('m.createdAt < :dateLimit')
                ->setParameter('dateLimit', $dateLimit)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();

            foreach ($messages as $message) {
                $this->entityManager->remove($message);
                $deleted++;
            }

            $this->entityManager->flush();
            $this->entityManager->clear();

            $io->progressAdvance($batchSize);
        } while (count($messages) > 0);

        $io->progressFinish();
        $io->success(sprintf('Se eliminaron %d mensajes correctamente.', $deleted));

        return Command::SUCCESS;
    }
}
