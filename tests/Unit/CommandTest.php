<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Command\CreateAdminUserCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandTest extends KernelTestCase
{
    private function getCommandTester(): CommandTester
    {
        $kernel = static::bootKernel();
        $app = new Application($kernel);
        $command = $app->find('app:user:admin');

        return new CommandTester($command);
    }

    public function testCreateNewAdminUserSucceeds(): void
    {
        $tester = $this->getCommandTester();
        $name = 'testadmin_' . substr(uniqid(), 0, 8);

        $exitCode = $tester->execute(['name' => $name, '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Created admin account', $tester->getDisplay());
    }

    public function testPromoteExistingUserToAdmin(): void
    {
        $tester = $this->getCommandTester();
        $name = 'testpromote_' . substr(uniqid(), 0, 8);

        // Create first
        $tester->execute(['name' => $name, '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        // Promote (run again without --password)
        $exitCode = $tester->execute(['name' => $name]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Promoted', $tester->getDisplay());
    }

    public function testRejectsTooLongName(): void
    {
        $tester = $this->getCommandTester();

        $exitCode = $tester->execute(['name' => str_repeat('a', 46), '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testRejectsEmptyName(): void
    {
        $tester = $this->getCommandTester();

        $exitCode = $tester->execute(['name' => '   ', '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testRejectsShortPassword(): void
    {
        $tester = $this->getCommandTester();

        $exitCode = $tester->execute(['name' => 'newuser', '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testRejectsMissingPasswordForNewUser(): void
    {
        $tester = $this->getCommandTester();
        $name = 'nopassuser_' . substr(uniqid(), 0, 8);

        $exitCode = $tester->execute(['name' => $name]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testRejectsResetPasswordWithoutPasswordOption(): void
    {
        $tester = $this->getCommandTester();
        $name = 'resetuser_' . substr(uniqid(), 0, 8);

        // Create first
        $tester->execute(['name' => $name, '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        // Try to reset without --password value
        $exitCode = $tester->execute(['name' => $name, '--reset-password' => true]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testPromoteRegularUserToAdmin(): void
    {
        // getCommandTester() calls bootKernel(); after that, getContainer() is available.
        $tester = $this->getCommandTester();
        $em     = static::getContainer()->get(EntityManagerInterface::class);

        $name = 'nonadmin_' . substr(uniqid('', true), 0, 10);

        // Create a user with ROLE_USER only — NOT ROLE_ADMIN.
        $user = (new User())
            ->setName($name)
            ->setHashpass(password_hash('pass12345', PASSWORD_DEFAULT))
            ->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();

        // The command finds the user, sees ROLE_ADMIN is absent, and appends it (line 59).
        $exitCode = $tester->execute(['name' => $name]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Promoted', $tester->getDisplay());
    }

    public function testResetPasswordWithValidPassword(): void
    {
        $tester = $this->getCommandTester();
        $name = 'resetok_' . substr(uniqid(), 0, 8);

        // Create first
        $tester->execute(['name' => $name, '--password' => 'REPLACE_WITH_LOCAL_SECRET']);

        // Reset password
        $exitCode = $tester->execute(['name' => $name, '--password' => 'REPLACE_WITH_LOCAL_SECRET', '--reset-password' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Promoted', $tester->getDisplay());
    }
}
