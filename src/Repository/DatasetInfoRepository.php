<?php
declare(strict_types=1);

namespace Survos\DataBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataBundle\Entity\DatasetInfo;

/**
 * @extends ServiceEntityRepository<DatasetInfo>
 */
final class DatasetInfoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetInfo::class);
    }
}
