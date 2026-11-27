<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Entity\User;
use App\Repository\HouseholdRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * There is no sign-up page: household members are created from the console.
 * The household is created on the fly if it doesn't exist yet.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a user (and their household if needed)')]
final readonly class CreateUserCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private HouseholdRepository $households,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address, used to sign in')] string $email,
        #[Option(description: 'Display name')] ?string $name = null,
        #[Option(description: 'Household name (created if missing)')] string $household = 'Casa',
        #[Option(description: 'Interface language: es or en')] string $locale = 'es',
        #[Option(description: 'Password (prompted if omitted)')] ?string $password = null,
    ): int {
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(\sprintf('"%s" is not a valid email address.', $email));

            return Command::INVALID;
        }
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(\sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }
        if (!\in_array($locale, User::LOCALES, true)) {
            $io->error('Locale must be one of: '.implode(', ', User::LOCALES));

            return Command::INVALID;
        }

        $name = trim($name ?? '');
        if ('' === $name) {
            $name = (string) strstr($email, '@', true);
        }
        $household = '' !== trim($household) ? trim($household) : 'Casa';

        $password ??= $io->askHidden('Password (min. 8 characters)', self::validatePassword(...));
        if (!\is_string($password) || mb_strlen($password) < 8) {
            $io->error('The password must have at least 8 characters.');

            return Command::INVALID;
        }

        // Anyone can sign up with Google, each in a household of their own: never join one of
        // theirs by mistake because it happens to have the same name.
        $matches = $this->households->findBy(['name' => $household]);
        if (\count($matches) > 1) {
            $io->error(\sprintf('%d households are called "%s". Rename yours in the database, or pick another name.', \count($matches), $household));

            return Command::FAILURE;
        }
        $householdEntity = $matches[0] ?? null;
        if (null === $householdEntity) {
            $householdEntity = new Household($household);
            $this->entityManager->persist($householdEntity);
            $io->note(\sprintf('Created household "%s".', $household));
        }

        $user = new User($email, $name, $householdEntity, $locale);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Created user %s in household "%s".', $user->getEmail(), $householdEntity->getName()));

        return Command::SUCCESS;
    }

    private static function validatePassword(mixed $value): string
    {
        if (!\is_string($value) || mb_strlen($value) < 8) {
            throw new \RuntimeException('The password must have at least 8 characters.');
        }

        return $value;
    }
}
