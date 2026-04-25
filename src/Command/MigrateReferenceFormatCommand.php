<?php

namespace App\Command;

use App\Entity\PaperReferences;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:migrate-references-format',
    description: 'Migrate PaperReferences from double-encoded JSON to flat arrays',
)]
class MigrateReferenceFormatCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refs = $this->entityManager->getRepository(PaperReferences::class)->findAll();
        $migrated = 0;

        foreach ($refs as $ref) {
            $data = $ref->getReference();

            // Detect old format: sequential array with exactly one string element (a JSON-encoded object)
            if (!empty($data) && array_keys($data) === [0] && is_string($data[0])) {
                try {
                    $decoded = json_decode($data[0], true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded) && !empty($decoded)) {
                        $ref->setReference($decoded);
                        $this->entityManager->persist($ref);
                        $migrated++;
                    }
                } catch (\JsonException $e) {
                    $output->writeln(sprintf(
                        '<comment>Skipping reference #%d: %s</comment>',
                        $ref->getId() ?? 0,
                        $e->getMessage()
                    ));
                }
            }
        }

        if ($migrated > 0) {
            $this->entityManager->flush();
        }

        $output->writeln(sprintf('<info>Migrated %d reference(s).</info>', $migrated));

        return Command::SUCCESS;
    }
}
