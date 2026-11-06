<?php

namespace App\Console\Commands;

use App\Ai\Eval\EvalCase;
use App\Ai\Eval\EvalReport;
use App\Ai\Eval\EvalRun;
use App\Ai\Eval\ExtractionEval;
use App\Ai\ItemEmbedder;
use App\Models\AiProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('extraction:eval {--provider= : Name des gespeicherten KI-Providers} {--runs=1 : Anzahl der Durchläufe}')]
#[Description('Misst die Textextraktion eines KI-Providers an den Testfällen in tests/Evals/extraction')]
class EvalExtraction extends Command
{
    public function handle(ExtractionEval $eval, EvalReport $report, ItemEmbedder $embedder): int
    {
        $runs = filter_var($this->option('runs'), FILTER_VALIDATE_INT);

        if ($runs === false || $runs < 1) {
            $this->error('--runs muss eine ganze Zahl ab 1 sein.');

            return self::FAILURE;
        }

        $name = $this->option('provider');

        if (! is_string($name) || $name === '') {
            $this->error('--provider ist erforderlich. Verfügbar: '.$this->availableNames());

            return self::FAILURE;
        }

        $provider = AiProvider::query()->where('name', $name)->first();

        if ($provider === null) {
            $this->error("Provider \"{$name}\" nicht gefunden. Verfügbar: ".$this->availableNames());

            return self::FAILURE;
        }

        try {
            $embedder->embed('test');
        } catch (Throwable $e) {
            $this->error('Der Embedding-Provider ist nicht erreichbar: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $cases = EvalCase::loadAll(base_path('tests/Evals/extraction'));
        } catch (RuntimeException $e) {
            $this->error('Die Testfälle sind fehlerhaft: '.$e->getMessage());

            return self::FAILURE;
        }

        $results = [];

        for ($i = 1; $i <= $runs; $i++) {
            $this->info("Durchlauf {$i} von {$runs} ...");
            $results[] = $eval->run($provider, $cases);
        }

        $text = $report->render($provider->name, $results);
        $path = 'evals/'.now()->format('Y-m-d_His').'-'.Str::slug($provider->name).'.txt';
        Storage::disk('local')->put($path, $text);

        foreach (explode("\n", rtrim($text)) as $line) {
            $this->line($line);
        }

        $this->info('Bericht gespeichert: '.Storage::disk('local')->path($path));

        return collect($results)->every(fn (EvalRun $run): bool => $run->allFailed()) ? self::FAILURE : self::SUCCESS;
    }

    private function availableNames(): string
    {
        return AiProvider::query()->orderBy('name')->pluck('name')->implode(', ') ?: '(keine)';
    }
}
