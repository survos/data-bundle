<?php

declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Survos\DataBundle\Entity\VocabLabel;
use Survos\DataBundle\Entity\VocabMap;
use Survos\DataBundle\Repository\VocabLabelRepository;
use Survos\DataBundle\Repository\VocabMapRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('vocab:export', 'Export VocabMap and VocabLabel rows as JSONL (stdout or file)')]
final class VocabExportCommand
{
    public function __construct(
        private readonly VocabMapRepository   $maps,
        private readonly VocabLabelRepository $labels,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Write to this file instead of stdout')]
        ?string $output = null,
        #[Option('Restrict to one language code')]
        ?string $lang = null,
        #[Option('Export VocabLabel rows instead of VocabMap')]
        bool $labels = false,
    ): int {
        $fh = $output !== null ? fopen($output, 'w') : \STDOUT;
        if (false === $fh) {
            $io->error("Cannot open $output for writing.");

            return Command::FAILURE;
        }

        $count = 0;

        try {
            if ($labels) {
                $qb = $this->labels->createQueryBuilder('v');
                if ($lang !== null) {
                    $qb->where('v.lang = :lang')->setParameter('lang', $lang);
                }
                foreach ($qb->getQuery()->toIterable() as $row) {
                    \assert($row instanceof VocabLabel);
                    fwrite($fh, json_encode($row->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
                    ++$count;
                }
            } else {
                $qb = $this->maps->createQueryBuilder('v');
                if ($lang !== null) {
                    $qb->where('v.lang = :lang')->setParameter('lang', $lang);
                }
                foreach ($qb->getQuery()->toIterable() as $row) {
                    \assert($row instanceof VocabMap);
                    fwrite($fh, json_encode($row->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n");
                    ++$count;
                }
            }
        } finally {
            if ($output !== null) {
                fclose($fh);
            }
        }

        $table = $labels ? 'VocabLabel' : 'VocabMap';
        $output !== null
            ? $io->success(sprintf('Exported %d %s row(s) to %s.', $count, $table, $output))
            : $io->writeln(sprintf('Exported %d %s row(s).', $count, $table), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
