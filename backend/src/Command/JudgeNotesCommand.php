<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\TasteProfile;
use App\Message\JudgeNotes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues every household with notes to have the recipes not yet judged against them judged
 * (after a failure, say). Saving the notes and changing a recipe do it on their own.
 */
#[AsCommand(name: 'app:judge-notes', description: 'Judge the recipes not yet read against their household\'s free-text notes')]
final readonly class JudgeNotesCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $queued = 0;
        foreach ($this->entityManager->getRepository(TasteProfile::class)->findAll() as $profile) {
            if (null !== $profile->getNotes()) {
                $this->bus->dispatch(new JudgeNotes($profile->getHousehold()->getId()->toRfc4122()));
                ++$queued;
            }
        }
        $io->success(\sprintf('%d household(s) queued.', $queued));

        return Command::SUCCESS;
    }
}
