<?php

declare(strict_types=1);

namespace App\Console;

use App\DTO\Input\UserData;
use App\Exceptions\ValidationException;
use App\Model\Enum\UserRole;
use App\Services\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Vytvoření uživatele z příkazové řádky – v produkci tak vznikne první administrátor
 * bez demo dat a bez hesla uloženého v kódu.
 */
#[AsCommand(name: 'app:user:create', description: 'Vytvoří nového uživatele (např. prvního administrátora)')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserService $userService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail (přihlašovací jméno)')
            ->addOption('first-name', null, InputOption::VALUE_REQUIRED, 'Jméno', 'Admin')
            ->addOption('last-name', null, InputOption::VALUE_REQUIRED, 'Příjmení', 'Administrátor')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Role (admin, manager, technician)', UserRole::Admin->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = UserRole::tryFrom((string) $input->getOption('role'));
        if ($role === null) {
            $io->error('Neplatná role.');

            return self::FAILURE;
        }

        $password = (string) $io->askHidden('Heslo (alespoň 8 znaků, malé i velké písmeno, číslice)');

        try {
            $id = $this->userService->create(new UserData(
                email: mb_strtolower(trim((string) $input->getArgument('email'))),
                firstName: (string) $input->getOption('first-name'),
                lastName: (string) $input->getOption('last-name'),
                phone: null,
                role: $role,
                isActive: true,
                password: $password,
            ), null);
        } catch (ValidationException $e) {
            $io->error(array_values($e->getFieldErrors()) ?: [$e->getMessage()]);

            return self::FAILURE;
        }

        $io->success(sprintf('Uživatel #%d (%s) byl vytvořen.', $id, $role->label()));

        return self::SUCCESS;
    }
}
