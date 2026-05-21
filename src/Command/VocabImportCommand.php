<?php

declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Entity\VocabLabel;
use Survos\DataBundle\Entity\VocabMap;
use Survos\DataBundle\Repository\VocabLabelRepository;
use Survos\DataBundle\Repository\VocabMapRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('vocab:import', 'Import VocabMap or VocabLabel rows from JSONL (stdin or file)')]
final class VocabImportCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VocabMapRepository     $maps,
        private readonly VocabLabelRepository   $labels,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Read from a .jsonl file instead of stdin')]
        ?string $input = null,
        #[Option('Import VocabLabel rows instead of VocabMap')]
        bool $labels = false,
        #[Option('Flush every N rows')]
        int $batchSize = 500,
    ): int {
        $imported = 0;
        $updated  = 0;

        foreach ($this->rows($input) as $row) {
            if ($labels) {
                $obj = $this->labels->upsert(
                    $row['contentType'],
                    $row['lang'],
                    $row['label'],
                    $row['model'] ?? null,
                );
                $obj->createdAt = isset($row['createdAt'])
                    ? new \DateTimeImmutable($row['createdAt'])
                    : $obj->createdAt;
            } else {
                $obj = $this->maps->upsert(
                    $row['lang'],
                    $row['keyword'],
                    $row['contentType'] ?? null,
                    (float) ($row['confidence'] ?? 0.0),
                    $row['model'] ?? null,
                );
                if (isset($row['createdAt'])) {
                    $obj->createdAt = new \DateTimeImmutable($row['createdAt']);
                }
            }

            $obj->id === null ? ++$imported : ++$updated;

            if (($imported + $updated) % $batchSize === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Done. Inserted: %d, Updated: %d.',
            $imported,
            $updated,
        ));

        return Command::SUCCESS;
    }

    /** @return iterable<array<string, mixed>> */
    private function rows(?string $input): iterable
    {
        $fh = $input !== null ? fopen($input, 'r') : \STDIN;
        if (false === $fh) {
            throw new \RuntimeException("Cannot open $input for reading.");
        }

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $row = json_decode($line, true);
            if (!\is_array($row)) {
                throw new \RuntimeException('Invalid JSONL row: '.$line);
            }
            yield $row;
        }

        if ($input !== null) {
            fclose($fh);
        }
    }
}
