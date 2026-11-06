<?php

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** A 1024-dimension unit vector pointing along the given axis. */
function axis(int $index): array
{
    $vector = array_fill(0, 1024, 0.0);
    $vector[$index] = 1.0;

    return $vector;
}

/**
 * Replaces the local disk with one on the same files that fails like a full disk, configured as in
 * production: the adapter throws and the disk turns that into a false result. Reads keep working,
 * so a temporary upload stays readable.
 */
function breakLocalDisk(int $writesBeforeFailure = 0, bool $truncatesWrites = false, bool $failsDeletes = false): void
{
    $root = Storage::disk('local')->path('');

    $adapter = new class($root, $writesBeforeFailure, $truncatesWrites, $failsDeletes) extends FlysystemLocalAdapter
    {
        public function __construct(string $root, private int $writesLeft, private bool $truncatesWrites, private bool $failsDeletes)
        {
            parent::__construct($root);
        }

        public function write(string $path, string $contents, Config $config): void
        {
            $this->countWrite($path);

            parent::write($path, $this->truncatesWrites ? '' : $contents, $config);
        }

        public function writeStream(string $path, $contents, Config $config): void
        {
            $this->countWrite($path);

            if ($this->truncatesWrites) {
                parent::write($path, '', $config);

                return;
            }

            parent::writeStream($path, $contents, $config);
        }

        public function delete(string $path): void
        {
            if ($this->failsDeletes) {
                throw UnableToDeleteFile::atLocation($path, 'broken disk');
            }

            parent::delete($path);
        }

        private function countWrite(string $path): void
        {
            if (! $this->truncatesWrites && $this->writesLeft-- <= 0) {
                throw UnableToWriteFile::atLocation($path, 'broken disk');
            }
        }
    };

    $disk = new LocalFilesystemAdapter(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false]);
    $disk->buildTemporaryUrlsUsing(fn (string $path): string => "http://localhost/{$path}");

    Storage::set('local', $disk);
}
