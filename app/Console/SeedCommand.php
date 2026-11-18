<?php

declare(strict_types=1);

namespace App\Console;

use App\Bootstrap;
use App\Console\Seed\DemoDataSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed', description: 'Naplní databázi demo daty (pouze mimo produkci)')]
final class SeedCommand extends Command
{
    public function __construct(
        private readonly DemoDataSeeder $seeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('if-empty', null, InputOption::VALUE_NONE, 'Spustit jen pokud v databázi nejsou žádní uživatelé')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bez potvrzení smazat stávající data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (Bootstrap::isProduction()) {
            $io->error('Demo data nelze vytvořit v produkčním prostředí (APP_ENV=production).');

            return self::FAILURE;
        }

        $isEmpty = $this->seeder->isDatabaseEmpty();
        if ($input->getOption('if-empty') && !$isEmpty) {
            $io->writeln('Databáze již obsahuje data – seed přeskočen.');

            return self::SUCCESS;
        }

        if (!$isEmpty && !$input->getOption('force') && !$io->confirm('Všechna stávající data budou smazána. Pokračovat?', false)) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $counts = $this->seeder->seed();

        $io->success(sprintf('Demo data vytvořena za %.1f s.', microtime(true) - $started));
        $io->table(['Tabulka', 'Počet'], array_map(null, array_keys($counts), array_values($counts)));
        $io->writeln('Přihlášení: admin@servishub.local / vedouci@servishub.local / technik@servishub.local (heslo z DEMO_PASSWORD)');

        return self::SUCCESS;
    }
}
