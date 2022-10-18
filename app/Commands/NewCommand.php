<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use ZipArchive;

class NewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'new';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create a new package';

    protected $fs;

    protected $data = [];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->dir = getcwd();
        $this->fs = new Filesystem(new LocalFilesystemAdapter($this->dir));
        $this->data['authorName'] = $this->ask('Author Name', $this->callCommand('git config user.name'));
        $this->data['authorUsername'] = $this->ask('Author Username', Str::slug($this->data['authorName']));
        $this->data['authorEmail'] = $this->ask('Author Email', $this->callCommand('git config user.email'));
        $this->data['vendorName'] = $this->ask('Vendor Name', 'My Org');
        $this->data['vendorSlug'] = $this->ask('Vendor Slug', Str::slug($this->data['vendorName']));
        $this->data['packageName'] = $this->ask('Package Name', 'My Package');
        $this->data['packageSlug'] = $this->ask('Package Slug', Str::slug($this->data['packageName']));
        $this->data['packageDescription'] = $this->ask('Package Description', 'Awesome Package');
        $this->data['templateRepo'] = $this->ask('Template Repository', 'devtical/compack-template-generic');
        $this->data['templateBranch'] = $this->ask('Template Branch', 'main');
        $this->data['vendorNamespace'] = Str::studly($this->data['vendorName']);
        $this->data['packageClassName'] = Str::studly($this->data['packageName']);
        $this->data['templateUrl'] = 'https://codeload.github.com/'.$this->data['templateRepo'].'/zip/'.$this->data['templateBranch'];
        $this->data['targetPath'] = $this->data['vendorSlug'].'/'.$this->data['packageSlug'];

        $this->download();
        $this->setup();

        $this->newLine(2);

        $dataTable = collect($this->data)
            ->map(function ($value, $key) {
                return [
                    'key' => $key,
                    'value' => $value,
                ];
            })->toArray();

        $this->table(
            ['Key', 'Value'],
            $dataTable
        );

        $this->notify($this->data['targetPath'].' has been created', 'Love beautiful..');
    }

    private function download()
    {
        $response = Http::get($this->data['templateUrl']);
        $zipPath = md5($this->data['templateRepo']).'.zip';

        if ($response->status() != 200) {
            return $this->error('Invalid template repository');
        }

        Storage::put($zipPath, $response->body());
        Storage::deleteDirectory($this->data['targetPath']);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CHECKCONS);
        $zip->extractTo(dirname($this->data['templateRepo']));
        $zip->close();

        Storage::move($this->data['templateRepo'].'-'.$this->data['templateBranch'], $this->data['targetPath']);
        Storage::deleteDirectory(explode('/', $this->data['templateRepo'])[0]);
        Storage::delete($zipPath);
    }

    private function setup()
    {
        $replacements = [
            ':author_name' => $this->data['authorName'],
            ':author_username' => $this->data['authorUsername'],
            ':author_email' => $this->data['authorEmail'],
            ':vendor_namespace' => $this->data['vendorNamespace'],
            ':vendor_name' => $this->data['vendorName'],
            ':vendor_slug' => $this->data['vendorSlug'],
            ':package_class_name' => $this->data['packageClassName'],
            ':package_description' => $this->data['packageDescription'],
            ':package_name' => $this->data['packageName'],
            ':package_slug' => $this->data['packageSlug'],
            ':year' => now()->format('Y'),
        ];

        $files = Storage::allFiles();

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $file) {
            $contents = Storage::get($file);
            $contents = str_replace(
                array_keys($replacements),
                array_values($replacements),
                $contents
            );

            Storage::put($file, $contents);

            $renameFiles = [
                'ExampleClass.php' => $this->data['packageClassName'].'.php',
                'ExampleTest.php' => $this->data['packageClassName'].'Test.php',
            ];

            foreach ($renameFiles as $key => $value) {
                if (Str::contains($file, $key)) {
                    $explode = explode('/', $file);
                    $filename = end($explode);
                    $newFile = str_replace($filename, $value, $file);

                    Storage::move($file, $newFile);
                }
            }

            $bar->advance();
        }

        $bar->finish();
    }

    private function callCommand($command)
    {
        return trim(shell_exec($command));
    }
}
