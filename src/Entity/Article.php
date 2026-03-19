<?php

declare(strict_types=1);

namespace App\Entity;

/*
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │  FEATURE: DOCTRINE ORM — ENTITIES                                       │
 * ├─────────────────────────────────────────────────────────────────────────┤
 * │  An Entity is a plain PHP class that Doctrine maps to a database table. │
 * │  Everything is configured via PHP 8 Attributes — no XML or YAML needed. │
 * │                                                                         │
 * │  Key attributes shown here:                                             │
 * │  • #[ORM\Entity]              → declares the class as a DB entity       │
 * │  • #[ORM\Table]               → customises the table name               │
 * │  • #[ORM\HasLifecycleCallbacks] → enables @PrePersist / @PreUpdate hooks│
 * │  • #[ORM\Column]              → maps a property to a DB column          │
 * │  • #[Assert\*]                → Symfony Validator constraints           │
 * │  • #[Groups]                  → Serializer groups for API output        │
 * └─────────────────────────────────────────────────────────────────────────┘
 */

use App\Repository\ArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Table(name: 'articles')]
#[ORM\HasLifecycleCallbacks]  // required to enable @PrePersist / @PreUpdate methods
class Article
{
    // ── Primary key ──────────────────────────────────────────────────────────
    // GeneratedValue uses DB auto-increment. The ?int allows null before insert.
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['article:read'])]
    private ?int $id = null;

    // ── Basic columns ─────────────────────────────────────────────────────────
    // NotBlank checks for non-empty string. Length validates min/max chars.
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Title cannot be blank.')]
    #[Assert\Length(min: 3, max: 255)]
    #[Groups(['article:read', 'article:write'])]
    private string $title = '';

    // unique: true adds a UNIQUE constraint at the DB level.
    // The slug is auto-generated from the title in the PrePersist lifecycle hook.
    #[ORM\Column(length: 255, unique: true)]
    #[Groups(['article:read'])]
    private string $slug = '';

    // Types::TEXT maps to a TEXT column (unlimited length) instead of VARCHAR.
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 10, minMessage: 'Content must be at least {{ limit }} characters.')]
    #[Groups(['article:read', 'article:write'])]
    private string $content = '';

    // Types::JSON maps to a JSON column; Doctrine handles (de)serialization.
    #[ORM\Column(type: Types::JSON)]
    #[Assert\All([new Assert\Type('string')])]   // validate every element in the array
    #[Groups(['article:read', 'article:write'])]
    private array $tags = [];

    #[ORM\Column]
    #[Groups(['article:read'])]
    private bool $published = false;

    // ── Timestamps ────────────────────────────────────────────────────────────
    // DateTimeImmutable is preferred over DateTime — it cannot be mutated
    // accidentally, making the code more predictable.
    #[ORM\Column]
    #[Groups(['article:read'])]
    private \DateTimeImmutable $createdAt;

    // nullable: true → column accepts NULL in the DB (updatedAt starts as null).
    #[ORM\Column(nullable: true)]
    #[Groups(['article:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['article:read'])]
    private ?\DateTimeImmutable $publishedAt = null;

    // ── Constructor ───────────────────────────────────────────────────────────
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Lifecycle callbacks ───────────────────────────────────────────────────
    // Doctrine calls #[ORM\PrePersist] just before the first INSERT.
    // Great for auto-generating values that depend on other properties.
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        // Auto-generate a URL-safe slug from the title if not set explicitly.
        // Uses symfony/string's ASCII slugger (available via composer).
        if ($this->slug === '') {
            $this->slug = $this->generateSlug($this->title);
        }
    }

    // #[ORM\PreUpdate] is called just before every subsequent UPDATE.
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function generateSlug(string $text): string
    {
        // \Symfony\Component\String\u() turns any string into a UnicodeString
        // that understands Unicode folding, accents, etc.
        $slug = \Symfony\Component\String\u($text)
            ->ascii()            // transliterate accents: é → e
            ->lower()            // lowercase
            ->replace(' ', '-')  // spaces → dashes
            ->replaceMatches('/[^a-z0-9\-]/', '')  // strip non-alphanumeric
            ->trim('-');

        // Append a short random suffix to guarantee uniqueness without a DB round-trip.
        return $slug.'-'.substr(uniqid('', false), -6);
    }

    // ── Getters & setters ─────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static
    {
        $this->title = $title;
        // Reset slug so PrePersist re-generates it if title changed on a new entity.
        $this->slug = '';
        return $this;
    }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }

    public function getContent(): string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    public function getTags(): array { return $this->tags; }
    public function setTags(array $tags): static { $this->tags = $tags; return $this; }

    public function isPublished(): bool { return $this->published; }
    public function publish(): static
    {
        $this->published  = true;
        $this->publishedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
}
