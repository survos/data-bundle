<?php

declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\VocabMapRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

/**
 * Cached classification of a foreign-language keyword to a ContentType slug.
 *
 * One row per (lang, norm_keyword). Null content_type = known miss — the term
 * has been evaluated and is not a classifiable genre/type. Stored so we never
 * re-ask the model for the same term.
 */
#[ORM\Entity(repositoryClass: VocabMapRepository::class)]
#[ORM\Table(name: 'vocab_map')]
#[ORM\UniqueConstraint(name: 'vocab_map_uniq', columns: ['lang', 'norm_keyword'])]
#[ORM\Index(name: 'vocab_map_content_type_idx', columns: ['content_type'])]
#[EntityMeta(icon: 'tabler:vocabulary', group: 'Vocabulary', label: 'Vocab Map', description: 'Keyword → ContentType classification cache')]
final class VocabMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 8)]
    #[Field(sortable: true, filterable: true, facet: true)]
    public string $lang;

    #[ORM\Column(type: Types::TEXT)]
    #[Field(searchable: true)]
    public string $keyword;

    #[ORM\Column(length: 255)]
    #[Field(searchable: true, sortable: true)]
    public string $normKeyword;

    /** ContentType slug (e.g. 'photograph'), or null = evaluated but not classifiable. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Field(sortable: true, filterable: true, facet: true)]
    public ?string $contentType = null;

    #[ORM\Column]
    #[Field(sortable: true)]
    public float $confidence = 0.0;

    /** Which model produced this classification. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Field(filterable: true, facet: true)]
    public ?string $model = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $lang, string $keyword)
    {
        $this->lang = $lang;
        $this->keyword = $keyword;
        $this->normKeyword = self::normalize($keyword);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function normalize(string $keyword): string
    {
        return mb_strtolower(trim($keyword));
    }

    public function toArray(): array
    {
        return [
            'lang'        => $this->lang,
            'keyword'     => $this->keyword,
            'normKeyword' => $this->normKeyword,
            'contentType' => $this->contentType,
            'confidence'  => $this->confidence,
            'model'       => $this->model,
            'createdAt'   => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $row): self
    {
        $obj = new self($row['lang'], $row['keyword']);
        $obj->contentType = $row['contentType'] ?? null;
        $obj->confidence  = (float) ($row['confidence'] ?? 0.0);
        $obj->model       = $row['model'] ?? null;
        if (isset($row['createdAt'])) {
            $obj->createdAt = new \DateTimeImmutable($row['createdAt']);
        }

        return $obj;
    }
}
