<?php

namespace App\Contracts;

use App\Contracts\Subject;

interface Observer
{
    public function update(Subject $subject): void;
}
