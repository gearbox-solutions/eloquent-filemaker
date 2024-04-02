<?php

namespace GearboxSolutions\EloquentFileMaker\Commands;

use Illuminate\Foundation\Console\ModelMakeCommand as LaravelModelMakeCommand;
use Symfony\Component\Console\Input\InputOption;

class FMModelMakeCommand extends LaravelModelMakeCommand
{
    protected $name = 'make:fm-model';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Eloquent FileMaker model class';

    public function handle()
    {
        parent::handle();

        $this->input->setOption('filemaker', true);
    }

    public function getStub()
    {
        $stub = parent::getStub();

        if ($this->option('filemaker')) {
            if ($this->option('pivot') || $this->option('morph-pivot')) {
                throw new \RuntimeException('This model type is not yet supported by Eloquent FileMaker.');
            }

            $stub = $this->resolveStubPath('/stubs/fm.model.stub');
        }

        return $stub;
    }

    /**
     * Resolve the fully-qualified path to the stub.
     *
     * @param  string  $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : __DIR__ . $stub;
    }

    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['filemaker', null, InputOption::VALUE_NONE, 'Use the FileMaker stub instead of base model stub'],
        ]);
    }
}
