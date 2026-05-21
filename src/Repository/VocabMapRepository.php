<?php

declare(strict_types=1);

namespace Survos\DataBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataBundle\Entity\VocabMap;

/**
 * @extends ServiceEntityRepository<VocabMap>
 */
class VocabMapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VocabMap::class);
    }

    /**
     * Look up a single keyword. Returns null if never evaluated, the row if it has been
     * (row->contentType may itself be null, meaning evaluated but not classifiable).
     */
    public function findByLangKeyword(string $lang, string $keyword): ?VocabMap
    {
        return $this->findOneBy([
            'lang' => $lang,
            'normKeyword' => VocabMap::normalize($keyword),
        ]);
    }

    /**
     * Given a list of keywords in a language, return the subset not yet in the cache.
     *
     * @param  string[] $keywords
     * @return string[]
     */
    public function findUncached(string $lang, array $keywords): array
    {
        if (!$keywords) {
            return [];
        }

        $normalized = array_map(VocabMap::normalize(...), $keywords);

        $cached = $this->createQueryBuilder('v')
            ->select('v.normKeyword')
            ->where('v.lang = :lang AND v.normKeyword IN (:norms)')
            ->setParameter('lang', $lang)
            ->setParameter('norms', $normalized)
            ->getQuery()
            ->getSingleColumnResult();

        $cachedSet = array_flip($cached);

        return array_values(array_filter(
            $keywords,
            fn (string $k) => !isset($cachedSet[VocabMap::normalize($k)])
        ));
    }

    /**
     * Upsert a classification result. Creates or updates the row for (lang, normKeyword).
     */
    public function upsert(
        string $lang,
        string $keyword,
        ?string $contentType,
        float $confidence,
        ?string $model,
    ): VocabMap {
        $row = $this->findByLangKeyword($lang, $keyword);

        if (null === $row) {
            $row = new VocabMap($lang, $keyword);
            $this->getEntityManager()->persist($row);
        }

        $row->contentType = $contentType;
        $row->confidence = $confidence;
        $row->model = $model;
        $row->updatedAt = new \DateTimeImmutable();

        return $row;
    }
}
