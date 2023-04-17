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
    public function getFunctions() : array
    {
        return [
            new TwigFunction('getLogOutUrl', [$this, 'getLogOutUrl']),
        ];
    }
    public function getLogOutUrl(string $rvCode, string $env):string {
        $url = ($env === 'dev') ? "http://" : "https://";
        return $url.$rvCode.'.episciences.org';
    }
}