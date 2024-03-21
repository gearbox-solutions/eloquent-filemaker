<?php

namespace GearboxSolutions\EloquentFileMaker\Commands;

use Illuminate\Foundation\Console\ModelMakeCommand as LaravelModelMakeCommand;
use Illuminate\Support\Str;
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
            $stub = Str::replace('/model.', '/fm.model.', $stub);

            throw_if(! file_exists($stub), new \RuntimeException('This model type is not yet supported by Eloquent FileMaker.'));
        }

        return $stub;
    }

    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['filemaker', null, InputOption::VALUE_NONE, 'Use the FileMaker stub instead of base model stub'],
        ]);
    }
}
