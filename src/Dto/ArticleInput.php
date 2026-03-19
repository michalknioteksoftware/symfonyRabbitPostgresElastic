<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /api/articles and PATCH /api/articles/{id}.
 *
 * #[MapRequestPayload] uses the Symfony Serializer to populate this class
 * from the JSON request body, then runs the Validator automatically.
 * If validation fails, Symfony returns a 422 — zero controller code needed.
 *
 * Note: public non-readonly properties are required so the ObjectNormalizer
 * can set values via property access after constructing the object.
 */
final class ArticleInput
{
    #[Assert\NotBlank(message: 'Title is required.')]
    #[Assert\Length(
        min: 3,
        max: 255,
        minMessage: 'Title must be at least {{ limit }} characters.',
        maxMessage: 'Title cannot exceed {{ limit }} characters.',
    )]
    public string $title = '';

    #[Assert\NotBlank(message: 'Content is required.')]
    #[Assert\Length(min: 10, minMessage: 'Content must be at least {{ limit }} characters.')]
    public string $content = '';

    #[Assert\All([
        new Assert\Type(type: 'string', message: 'Each tag must be a string.'),
        new Assert\Length(max: 50),
    ])]
    public array $tags = [];
}
