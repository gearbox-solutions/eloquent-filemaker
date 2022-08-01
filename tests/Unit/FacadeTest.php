<?php

namespace Tests\Unit;

use BlueFeather\EloquentFileMaker\Support\Facades\FM;
use Tests\TestCase;

class FacadeTest extends TestCase
{
    /**
     * The FM facade layout method sets the table name
     *
     * @return void
     */
    public function test_the_fm_facade_layout_sets_the_table_name()
    {
        $builder = FM::layout('pet');

        $this->assertEquals('pet', $builder->from);
    }

    /**
     * The FM facade table method sets the table name
     *
     * @return void
     */
    public function test_the_fm_facade_table_sets_the_table_name()
    {
        $builder = FM::table('pet');

        $this->assertEquals('pet', $builder->from);
    }
}
