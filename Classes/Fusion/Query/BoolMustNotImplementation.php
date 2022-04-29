<?php
namespace Zinc\NeosSearch\Fusion\Query;

use Neos\Flow\Annotations as Flow;

class BoolMustNotImplementation extends BoolAbstractImplementation
{
    protected $boolType = 'must_not';
}
