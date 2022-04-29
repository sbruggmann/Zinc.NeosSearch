<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;

class BoolShouldImplementation extends BoolAbstractImplementation
{
    protected $boolType = 'should';
}
