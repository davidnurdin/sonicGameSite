<?php

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ApiKey> */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    public function findByKey(string $key): ?ApiKey
    {
        if (!str_starts_with($key, ApiKey::PREFIX) || strlen($key) !== strlen(ApiKey::PREFIX) + 40) return null;

        return $this->findOneBy(['hash' => ApiKey::hash($key)]);
    }

    /** @return list<ApiKey> */
    public function ofUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'DESC']);
    }
}
