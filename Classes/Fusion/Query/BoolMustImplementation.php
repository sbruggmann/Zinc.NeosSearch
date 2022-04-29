<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;

class BoolMustImplementation extends BoolAbstractImplementation
{
    protected $boolType = 'must';
}
