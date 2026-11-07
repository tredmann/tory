<?php

use App\Models\User;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Connects an OAuth client to Tory the way Claude does: dynamic registration, authorization with
 * PKCE (S256) as the given user, approval on the consent page and the code exchange.
 *
 * @return array{access_token: string, refresh_token: string, client_id: string}
 */
function issueMcpTokens(User $user, string $clientName = 'Claude', string $redirect = 'https://claude.ai/api/mcp/auth_callback', ?string $existingClientId = null): array
{
    $clientId = $existingClientId ?? test()->postJson('/oauth/register', ['client_name' => $clientName, 'redirect_uris' => [$redirect]])
        ->assertCreated()
        ->json('client_id');

    $verifier = Str::random(64);
    $state = Str::random(16);

    test()->flushSession();
    auth()->forgetGuards();

    // Routes keep their controller (and its guard) between requests; a second user must not inherit the first.
    foreach (Route::getRoutes() as $route) {
        $route->flushController();
    }

    $authorization = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => $state,
        'code_challenge' => strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'),
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    $approval = test()->post('/oauth/authorize', [
        'state' => $state,
        'client_id' => $clientId,
        'auth_token' => $authorization->viewData('authToken'),
    ])->assertRedirect();

    parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);

    $tokens = test()->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk();

    auth()->forgetGuards();

    return [
        'access_token' => $tokens->json('access_token'),
        'refresh_token' => $tokens->json('refresh_token'),
        'client_id' => $clientId,
    ];
}

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

function jpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);

    return ob_get_clean();
}

function png(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function gif(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagegif($image);

    return ob_get_clean();
}

function jpegWithOrientationSix(int $width, int $height): string
{
    $jpeg = jpeg($width, $height);
    $app1 = "\xFF\xE1\x00\x22"."Exif\x00\x00"."MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}
