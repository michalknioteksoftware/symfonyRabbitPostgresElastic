<?php

declare(strict_types=1);

namespace App\Command;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: CONSOLE COMMAND                                               │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  Symfony Console lets you write CLI tools that share the full           │
 * │  application context — DI container, Doctrine, services — everything.  │
 * │                                                                         │
 * │  Run it with:                                                           │
 * │  docker compose exec app php bin/console app:article-stats              │
 * │  docker compose exec app php bin/console app:article-stats --tag=php   │
 * │  docker compose exec app php bin/console app:article-stats --format=json│
 * │                                                                         │
 * │  Key concepts shown:                                                    │
 * │  • #[AsCommand]    → registers command without services.yaml config     │
 * │  • Arguments       → required positional inputs                        │
 * │  • Options         → optional named flags (--tag, --format)             │
 * │  • SymfonyStyle    → rich terminal output (tables, progress, questions) │
 * │  • Return codes    → Command::SUCCESS / FAILURE / INVALID               │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Repository\ArticleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// #[AsCommand] registers this class as a console command automatically.
// 'description' appears in the 'php bin/console list' output.
#[AsCommand(
    name: 'app:article-stats',
    description: 'Display article statistics: published vs draft counts.',
)]
final class ArticleStatsCommand extends Command
{
    // Services are injected via the constructor — same DI pattern as controllers.
    public function __construct(private readonly ArticleRepository $articles)
    {
        parent::__construct();
    }

    // configure() defines arguments and options.
    // Arguments are positional (required by default).
    // Options are named flags (always optional).
    protected function configure(): void
    {
        $this
            ->addOption(
                'tag',           // option name (--tag)
                't',             // shortcut (-t)
                InputOption::VALUE_OPTIONAL,
                'Filter articles by this tag.',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output format: table (default) or json.',
                'table',         // default value
            );
    }

    // execute() contains the command logic.
    // SymfonyStyle wraps $input/$output with helper methods for rich CLI output.
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // ── Read options ───────────────────────────────────────────────────────
        $tag    = $input->getOption('tag');
        $format = $input->getOption('format');

        $io->title('Article Statistics');

        // ── Database queries ───────────────────────────────────────────────────
        $counts = $this->articles->countByStatus();

        $stats = [
            'published' => $counts['published'],
            'draft'     => $counts['draft'],
            'total'     => $counts['published'] + $counts['draft'],
        ];

        if ($tag !== null) {
            // Demonstrate using a repository method with a filter.
            $byTag = $this->articles->findByTag((string) $tag);
            $stats['by_tag'] = count($byTag);

            $io->note(sprintf('Filtering for tag: "%s"', $tag));
        }

        // ── Output: JSON ───────────────────────────────────────────────────────
        if ($format === 'json') {
            $io->writeln(json_encode($stats, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // ── Output: Table ──────────────────────────────────────────────────────
        // $io->table() renders an ASCII table — great for structured data.
        $rows = [
            ['Published', $stats['published']],
            ['Draft',     $stats['draft']],
            ['Total',     $stats['total']],
        ];

        if (isset($stats['by_tag'])) {
            $rows[] = [sprintf('Tagged "%s"', $tag), $stats['by_tag']];
        }

        $io->table(['Metric', 'Count'], $rows);

        // ── Conditional messaging ──────────────────────────────────────────────
        // $io->success / warning / error / info display styled blocks.
        if ($stats['total'] === 0) {
            $io->warning('No articles found. Create some via POST /api/articles.');
        } else {
            $ratio = $stats['total'] > 0
                ? round($stats['published'] / $stats['total'] * 100)
                : 0;
            $io->success(sprintf('%d%% of articles are published.', $ratio));
        }

        // Return Command::SUCCESS (0) on success, Command::FAILURE (1) on error.
        // Shell scripts and CI pipelines check this exit code.
        return Command::SUCCESS;
    }
}
