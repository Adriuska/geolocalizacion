<?php

namespace App\Command;

use App\Entity\Usuarios;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mark-inactive-users',
    description: 'Marca como offline a usuarios inactivos (última actividad > 5 minutos)',
)]
class MarkInactiveUsersCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Marcando usuarios inactivos como offline');

        $repository = $this->entityManager->getRepository(Usuarios::class);

        // Obtener todos los usuarios online
        $onlineUsers = $repository->findBy(['isOnline' => true]);

        $markedOffline = 0;
        $now = new \DateTime();

        foreach ($onlineUsers as $user) {
            $lastActivity = $user->getLastActivity();
            $diff = $now->getTimestamp() - $lastActivity->getTimestamp();

            // Si la última actividad fue hace más de 5 minutos (300 segundos)
            if ($diff > 300) {
                $user->setIsOnline(false);
                $markedOffline++;
            }
        }

        if ($markedOffline > 0) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Proceso completado. %d usuario(s) marcado(s) como offline.',
            $markedOffline
        ));

        $io->table(
            ['Estadística', 'Valor'],
            [
                ['Usuarios verificados', count($onlineUsers)],
                ['Usuarios marcados offline', $markedOffline],
                ['Tiempo límite', '5 minutos de inactividad']
            ]
        );

        return Command::SUCCESS;
    }
}
