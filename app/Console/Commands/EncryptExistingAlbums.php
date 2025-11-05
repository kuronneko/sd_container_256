<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class EncryptExistingAlbums extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'albums:encrypt-existing {--dry-run : Show changes without writing them} {--batch=100 : Process this many rows per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Encrypt existing albums.images values if they are not already encrypted';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $batch = (int) $this->option('batch');


        $this->info('Scanning albums table for non-encrypted columns...');

        $columnsToCheck = [
            'images',
            'positive',
            'negative',
            'metadata',
            'metadata_prompt',
            'metadata_workflow',
            'comment',
            'loras',
            'seed',
            'steps',
            'cfg',
            'sampler_name',
            'scheduler',
            'denoise',
            'ckpt_name',
            'width',
            'height',
        ];

        // Filter to only columns that actually exist in the table
        $availableColumns = array_filter($columnsToCheck, fn($col) => Schema::hasColumn('albums', $col));

        if (empty($availableColumns)) {
            $this->info('No target columns found to encrypt. Aborting.');
            return 0;
        }

        $query = DB::table('albums')->select(array_merge(['id'], $availableColumns));
        $total = $query->count();
        $this->info("Found {$total} album rows to inspect (will process in batches of {$batch}). Columns: " . implode(', ', $availableColumns));

        $processed = 0;
        $encryptedCount = 0;
        $skippedCount = 0;

        $query->orderBy('id')->chunk($batch, function ($rows) use (&$processed, &$encryptedCount, &$skippedCount, $dryRun, $availableColumns) {
            foreach ($rows as $row) {
                $processed++;
                $id = $row->id;

                $updates = [];
                foreach ($availableColumns as $col) {
                    $raw = $row->{$col};

                    if (is_null($raw) || $raw === '') {
                        continue;
                    }

                    // Detect encrypted value
                    $alreadyEncrypted = false;
                    try {
                        Crypt::decryptString($raw);
                        $alreadyEncrypted = true;
                    } catch (\Throwable $e) {
                        $alreadyEncrypted = false;
                    }

                    if ($alreadyEncrypted) {
                        $skippedCount++;
                        continue;
                    }

                    // Prepare plaintext value for encryption
                    if ($col === 'images') {
                        // images may be JSON array, uuid=>path map, serialized, or plain string
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $arr = array_values($decoded);
                        } else {
                            $arr = [$raw];
                            if (is_string($raw)) {
                                $maybe = @unserialize($raw);
                                if ($maybe !== false && is_array($maybe)) {
                                    $arr = array_values($maybe);
                                }
                            }
                        }
                        $plaintext = json_encode($arr);
                    } else {
                        // For other fields just cast to string (or keep JSON if array)
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $plaintext = json_encode($decoded);
                        } else {
                            $plaintext = (string) $raw;
                        }
                    }

                    $encrypted = Crypt::encryptString($plaintext);
                    $updates[$col] = $encrypted;
                }

                if (empty($updates)) {
                    continue;
                }

                $updates['updated_at'] = Carbon::now();

                if ($dryRun) {
                    $this->line("[DRY] Would update album {$id}: " . implode(', ', array_keys($updates)));
                } else {
                    DB::table('albums')->where('id', $id)->update($updates);
                    $this->line("Encrypted album {$id}: " . implode(', ', array_keys($updates)));
                }

                $encryptedCount += count($updates);
            }
        });

        $this->info("Done. Processed: {$processed}. Encrypted fields updates total: {$encryptedCount}. Skipped fields: {$skippedCount}.");

        return 0;
    }
}
