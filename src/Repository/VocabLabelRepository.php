<?php

declare(strict_types=1);

namespace Survos\DataBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataBundle\Entity\VocabLabel;

/**
 * @extends ServiceEntityRepository<VocabLabel>
 */
class VocabLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VocabLabel::class);
    }

    public function findLabel(string $contentType, string $lang): ?string
    {
        $row = $this->findOneBy(['contentType' => $contentType, 'lang' => $lang]);

        return $row?->label;
    }

    /**
     * Returns content types that have no label for the given language.
     *
     * @param  string[] $contentTypeSlugs
     * @return string[]
     */
    public function findUnlabelled(string $lang, array $contentTypeSlugs): array
    {
        if (!$contentTypeSlugs) {
            return [];
        }

        $labelled = $this->createQueryBuilder('v')
            ->select('v.contentType')
            ->where('v.lang = :lang AND v.contentType IN (:types)')
            ->setParameter('lang', $lang)
            ->setParameter('types', $contentTypeSlugs)
            ->getQuery()
            ->getSingleColumnResult();

        $labelledSet = array_flip($labelled);

        return array_values(array_filter(
            $contentTypeSlugs,
            fn (string $t) => !isset($labelledSet[$t])
        ));
    }

    /**
     * Upsert a label for a content type in a language.
     */
    public function upsert(
        string $contentType,
        string $lang,
        string $label,
        ?string $model,
    ): VocabLabel {
        $row = $this->findOneBy(['contentType' => $contentType, 'lang' => $lang]);

        if (null === $row) {
            $row = new VocabLabel($contentType, $lang, $label);
            $this->getEntityManager()->persist($row);
        } else {
            $row->label = $label;
            $row->model = $model;
        }

        return $row;
    }
}
