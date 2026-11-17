<?php

declare(strict_types=1);

namespace Survos\DataBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DataBundle\Service\RelationCollector;
use Survos\DataContracts\Dto\Link\LinkRow;
use Survos\DataContracts\Dto\Link\LinkTypeRow;
use Survos\DataContracts\Link\LinkFile;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\Core;
use Survos\DataContracts\Vocabulary\ItemField;
use Symfony\Component\Filesystem\Filesystem;

final class RelationCollectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/creator-cores-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testMixedCreatorsHaveSeparateCoresAndTheSameRole(): void
    {
        $collector = new RelationCollector($this->dir);
        $collector->add(Core::PERSON, 'created', 'created_by', Core::OBJECT, '1', ['John Smith', 'Boston Public Library', 'Smith, Jane, 1900-1980'], personName: true);
        self::assertSame(['cores' => 2, 'links' => 3], $collector->finalize());
        $people = $this->rows(Core::PERSON);
        self::assertSame([ContentType::PERSON, ContentType::PERSON], array_column($people, ItemField::CONTENT_TYPE));
        self::assertSame(1900, $people[1]['birth']);
        self::assertSame(ContentType::ORGANIZATION, $this->rows(Core::ORGANISATION)[0][ItemField::CONTENT_TYPE]);
        $links = iterator_to_array(LinkFile::readLinks($this->dir));
        self::assertSame([Core::PERSON, Core::ORGANISATION, Core::PERSON], array_column($links, 'subjectCore'));
        self::assertSame(['created', 'created', 'created'], array_column($links, 'predicate'));
        foreach (LinkFile::readTypes($this->dir) as $type) {
            self::assertSame('created_by', $type->reverseCode);
        }
    }

    public function testRebuildMovesLegacyOrganizationsWithoutErasingOtherProducers(): void
    {
        $old = new LinkTypeRow('created', Core::PERSON, Core::OBJECT, reverseCode: 'created_by');
        $tour = new LinkTypeRow('has_stop', 'tour', Core::OBJECT);
        $other = new LinkTypeRow('created', Core::PERSON, Core::DOCUMENT);
        LinkFile::merge($this->dir, [$old, $tour, $other], [
            LinkRow::of($old, 'boston-public-library', '1'),
            LinkRow::of($tour, 'tour-1', '1'),
            LinkRow::of($other, 'jane-smith', 'document-1'),
        ], gzip: true);
        file_put_contents($this->dir.'/per.jsonl.gz', gzencode(implode("\n", array_map(json_encode(...), [
            [ItemField::ID => 'boston-public-library', 'label' => 'Boston Public Library', ItemField::CONTENT_TYPE => 'org'],
            [ItemField::ID => 'jane-smith', 'label' => 'Jane Smith', ItemField::CONTENT_TYPE => ContentType::PERSON],
            [ItemField::ID => 'provider-person', 'label' => 'Provider person', ItemField::CONTENT_TYPE => ContentType::PERSON],
        ]))."\n"));
        for ($run = 0; $run < 2; ++$run) {
            $collector = new RelationCollector($this->dir);
            $collector->add(Core::PERSON, 'created', 'created_by', Core::OBJECT, '1', ['Boston Public Library'], personName: true);
            $collector->finalize();
            self::assertSame(['jane-smith', 'provider-person'], array_column($this->rows(Core::PERSON), ItemField::ID));
            self::assertCount(1, $this->rows(Core::ORGANISATION));
            $links = iterator_to_array(LinkFile::readLinks($this->dir));
            self::assertCount(3, $links);
            self::assertSame(['tour:has_stop:obj', 'per:created:doc', 'org:created:obj'], array_map(static fn (LinkRow $link) => $link->typeKey(), $links));
            self::assertFileDoesNotExist($this->dir.'/per.jsonl.gz');
        }
    }

    public function testNumericCreatorReferencesDoNotOverwriteProviderCores(): void
    {
        file_put_contents($this->dir.'/per.jsonl', "provider-owned\n");
        $collector = new RelationCollector($this->dir);
        $collector->add(Core::PERSON, 'created', 'created_by', Core::OBJECT, '1', ['123', '', null], personName: true);
        self::assertSame(['cores' => 0, 'links' => 0], $collector->finalize());
        self::assertSame("provider-owned\n", file_get_contents($this->dir.'/per.jsonl'));
    }

    private function rows(string $core): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->dir.'/'.$core.'.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
