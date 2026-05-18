<?php
// src/Twig/AppExtension.php
namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EpiExtractExtension extends AbstractExtension
{
    /**
     * @return TwigFunction[]
     */
    #[\Override]
    public function getFunctions() : array
    {
        return [new TwigFunction('formatcsl', [$this, 'formatcsl']),];
    }
}