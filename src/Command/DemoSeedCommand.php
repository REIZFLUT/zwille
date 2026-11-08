<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\DemoSeeder;
use App\Domain\HostRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:demo:seed', description: 'Fills an empty installation with demo data for evaluation')]
final readonly class DemoSeedCommand
{
    public function __construct(
        private DemoSeeder $seeder,
        private HostRegistry $hosts,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Password for the three demo users (min. 12 characters); a random one is generated otherwise')] ?string $password = null,
    ): int {
        if (!$this->seeder->isEmptyInstallation()) {
            $io->error('Demo data is only added to an empty installation (no users, no links). Use a fresh database.');

            return Command::FAILURE;
        }
        foreach (DemoSeeder::DOMAINS as $hostname) {
            if ($this->hosts->isAdminHost($hostname)) {
                $io->error(\sprintf('%s is listed in APP_ADMIN_HOSTS but is a demo short-link domain.', $hostname));

                return Command::FAILURE;
            }
        }
        if (null !== $password && mb_strlen($password) < 12) {
            $io->error('The password needs at least 12 characters.');

            return Command::FAILURE;
        }
        $password ??= rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');

        $result = $this->seeder->seed($password);

        $io->success(\sprintf('Demo data added: %d links, %d clicks.', $result['links'], $result['clicks']));
        $io->definitionList(
            ['Sign in' => \sprintf('%s (administrator)', DemoSeeder::ADMIN_EMAIL)],
            ['Other users' => implode(', ', array_slice($result['users'], 1))],
            ['Password' => $password],
            ['Short links' => implode(', ', array_map(static fn (string $d): string => $d.'/…', $result['domains']))],
        );
        $io->note('*.localhost resolves to this machine in current browsers; try the short link go.localhost/herbst on the port the app runs on.');

        return Command::SUCCESS;
    }
}
