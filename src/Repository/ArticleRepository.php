<?php

declare(strict_types=1);

namespace App\Repository;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: DOCTRINE REPOSITORY — QUERYBUILDER & DQL                      │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  A Repository is the place to put all database queries for one entity.  │
 * │  It gives you three levels of querying power:                           │
 * │                                                                         │
 * │  1. Magic finder methods → findBy(), findOneBy(), find(), findAll()     │
 * │  2. QueryBuilder         → object-oriented query DSL                    │
 * │  3. DQL (Doctrine Query Language) → SQL-like but entity-aware           │
 * │                                                                         │
 * │  The parent ServiceEntityRepository wires the EntityManager for you.   │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        // The parent constructor receives the entity class so every method
        // (find, findBy, findAll…) knows which table / entity to target.
        parent::__construct($registry, Article::class);
    }

    // ── QueryBuilder ──────────────────────────────────────────────────────────
    // QueryBuilder builds queries programmatically. Great when conditions are
    // dynamic (e.g. optional filters). Alias 'a' is used throughout.

    /**
     * Fetch paginated published articles ordered by newest first.
     *
     * @return Paginator<Article>   Doctrine's Paginator wraps the query result
     *                              and exposes count() + ArrayAccess.
     */
    public function findPublished(int $page = 1, int $perPage = 10): Paginator
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.published = :published')
            ->setParameter('published', true)
            ->orderBy('a.publishedAt', 'DESC')  // most recent first
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        // Paginator executes a COUNT query automatically for total pages.
        return new Paginator($qb);
    }

    /**
     * Full-text search across title and content (LIKE — for demos without
     * a dedicated search engine). See ElasticsearchService for production search.
     *
     * @return Article[]
     */
    public function search(string $term): array
    {
        return $this->createQueryBuilder('a')
            ->where('LOWER(a.title) LIKE :term OR LOWER(a.content) LIKE :term')
            // Always use parameters — never concatenate user input into queries!
            ->setParameter('term', '%'.strtolower($term).'%')
            ->andWhere('a.published = true')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find articles that contain a specific tag.
     *
     * MEMBER OF only works for ORM associations (OneToMany etc.), not JSON
     * columns. For PostgreSQL JSONB we drop to DBAL native SQL to use the
     * @> (contains) operator, then re-hydrate entities via ORM.
     *
     * @return Article[]
     */
    public function findByTag(string $tag, int $limit = 5): array
    {
        // DBAL gives us direct SQL access for DB-specific features.
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM articles
             WHERE published = true
               AND tags::jsonb @> :tag::jsonb
             ORDER BY published_at DESC
             LIMIT :lim',
            ['tag' => json_encode([$tag]), 'lim' => $limit],
            ['lim' => ParameterType::INTEGER],
        );

        if (empty($ids)) {
            return [];
        }

        // Hydrate full entities via ORM so callers always get Article objects.
        return $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    // ── Native DQL query ──────────────────────────────────────────────────────

    /**
     * Aggregate: total published vs draft articles.
     * Shows DQL SELECT with COUNT + GROUP BY.
     *
     * @return array{published: int, draft: int}
     */
    public function countByStatus(): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(
                'SELECT a.published, COUNT(a.id) as total
                 FROM App\Entity\Article a
                 GROUP BY a.published'
            )
            ->getResult();

        $result = ['published' => 0, 'draft' => 0];
        foreach ($rows as $row) {
            $key          = $row['published'] ? 'published' : 'draft';
            $result[$key] = (int) $row['total'];
        }

        return $result;
    }

    // ── Save / remove helpers ─────────────────────────────────────────────────
    // Wrapping persist/flush keeps controllers thin; they just call save().

    public function save(Article $article, bool $flush = true): void
    {
        $this->getEntityManager()->persist($article);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Article $article, bool $flush = true): void
    {
        $this->getEntityManager()->remove($article);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
