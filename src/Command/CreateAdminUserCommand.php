<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:admin', description: 'Create or promote an administrator account.')]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Username for the admin account.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for a new or reset admin account.')
            ->addOption('reset-password', null, InputOption::VALUE_NONE, 'Reset the password when the user already exists.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = trim((string) $input->getArgument('name'));
        $password = $input->getOption('password');
        $resetPassword = (bool) $input->getOption('reset-password');

        if ($name === '' || mb_strlen($name) > 45) {
            $io->error('Name must be 1-45 characters.');

            return Command::INVALID;
        }

        if ($password !== null && (!is_string($password) || mb_strlen($password) < 6)) {
            $io->error('Password must be at least 6 characters.');

            return Command::INVALID;
        }

        $user = $this->users->findOneByName($name);
        if ($user instanceof User) {
            $roles = $user->getRoles();
            if (!in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);

            if ($resetPassword) {
                if (!is_string($password) || $password === '') {
                    $io->error('Use --password when resetting an existing password.');

                    return Command::INVALID;
                }
                $user->setHashpass($this->hashPassword($password));
            }

            $this->entityManager->flush();
            $io->success(sprintf('Promoted "%s" to admin.', $name));

            return Command::SUCCESS;
        }

        if (!is_string($password) || $password === '') {
            $io->error('Use --password to create a new admin account.');

            return Command::INVALID;
        }

        $user = (new User())
            ->setName($name)
            ->setHashpass($this->hashPassword($password))
            ->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('Created admin account "%s".', $name));

        return Command::SUCCESS;
    }

    private function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Unable to hash password.'); // @codeCoverageIgnore
        }

        return $hash;
    }
}
