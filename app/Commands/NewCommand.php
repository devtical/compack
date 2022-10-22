<?php

namespace App\Commands;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
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
        $this->notify('Your package has been created', $this->data['targetPath']);
    }

    /**
     * Download and extract template repository.
     *
     * @return mixed
     */
    private function download()
    {
        $isPath = realpath($this->data['templateRepo']);

        if ($isPath) {
            config([
                'filesystems.disks' => array_merge(config('filesystems.disks'), [
                    'relative' => [
                        'driver' => 'local',
                        'root' => $isPath,
                    ],
                ]),
            ]);

            $files = Storage::disk('relative')->allFiles();

            foreach ($files as $file) {
                if (! Str::of($file)->contains('.git/')) {
                    $this->info($file);
                    Storage::put($this->data['targetPath'].'/'.$file, Storage::disk('relative')->get($file));
                }
            }
        } else {
            $explode = explode('/', $this->data['templateRepo']);
            $templateOrg = $explode[0];
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

            $this->data['vendorSlug'] == $templateOrg
                ? Storage::deleteDirectory($this->data['templateRepo'])
                : Storage::deleteDirectory($templateOrg);

            Storage::delete($zipPath);
        }
    }

    /**
     * Setup package.
     *
     * @return mixed
     */
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

    /**
     * Run command
     *
     * @return mixed
     */
    private function callCommand($command)
    {
        return trim(shell_exec($command));
    }
}
