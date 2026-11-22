<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\PasswordReset;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Set someone's password from the server, e.g. when email isn't set up. Signs them out everywhere. */
#[AsCommand(name: 'app:user:password', description: "Set a user's password")]
final readonly class UserPasswordCommand
{
    public function __construct(
        private UserRepository $users,
        private PasswordReset $passwords,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address of the user')] string $email,
        #[Option(description: 'New password (prompted if omitted)')] ?string $password = null,
    ): int {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(\sprintf('No user with email "%s".', $email));

            return Command::FAILURE;
        }

        $answer = $password ?? $io->askHidden('New password');
        $password = \is_string($answer) ? $answer : '';
        if (mb_strlen($password) < PasswordReset::MIN_LENGTH) {
            $io->error(\sprintf('The password needs at least %d characters.', PasswordReset::MIN_LENGTH));

            return Command::INVALID;
        }

        $this->passwords->setPassword($user, $password);
        $io->success(\sprintf('Password changed for %s; their devices are signed out.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
