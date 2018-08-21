<?php

declare(strict_types=1);

namespace Doctrine\RST\Nodes;

use Doctrine\RST\Document;

abstract class FigureNode extends Node
{
    /** @var ImageNode */
    protected $image;

    /** @var null|Document */
    protected $document;

    public function __construct(ImageNode $image, ?Document $document = null)
    {
        parent::__construct();

        $this->image    = $image;
        $this->document = $document;
    }
}