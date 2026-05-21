<?php

declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\VocabLabelRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;

/**
 * AI-generated display label for a ContentType slug in a given language.
 *
 * One row per (content_type, lang). Used to render facet sidebar labels
 * in the source language rather than showing raw foreign keywords.
 *
 * Example: ('photograph', 'fra') → 'Photographie'
 *          ('map', 'deu')        → 'Karte'
 *
 * Generated once per language via a single Claude call covering all ~22
 * ContentType slugs, then cached here permanently.
 */
#[ORM\Entity(repositoryClass: VocabLabelRepository::class)]
#[ORM\Table(name: 'vocab_label')]
#[ORM\UniqueConstraint(name: 'vocab_label_uniq', columns: ['content_type', 'lang'])]
#[EntityMeta(icon: 'tabler:language', group: 'Vocabulary', label: 'Vocab Label', description: 'ContentType display labels by language')]
final class VocabLabel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** ContentType slug — matches a ContentType constant value. */
    #[ORM\Column(length: 64)]
    #[Field(sortable: true, filterable: true, facet: true)]
    public string $contentType;

    #[ORM\Column(length: 8)]
    #[Field(sortable: true, filterable: true, facet: true)]
    public string $lang;

    /** Human-readable label in the target language. */
    #[ORM\Column(length: 255)]
    #[Field(searchable: true, sortable: true)]
    public string $label;

    /** Which model generated this label. */
    #[ORM\Column(length: 64, nullable: true)]
    #[Field(filterable: true, facet: true)]
    public ?string $model = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $contentType, string $lang, string $label)
    {
        $this->contentType = $contentType;
        $this->lang = $lang;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'contentType' => $this->contentType,
            'lang'        => $this->lang,
            'label'       => $this->label,
            'model'       => $this->model,
            'createdAt'   => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $row): self
    {
        $obj = new self($row['contentType'], $row['lang'], $row['label']);
        $obj->model = $row['model'] ?? null;
        if (isset($row['createdAt'])) {
            $obj->createdAt = new \DateTimeImmutable($row['createdAt']);
        }

        return $obj;
    }
}
