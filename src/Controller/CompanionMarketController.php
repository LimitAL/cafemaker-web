<?php

namespace App\Controller;

use App\Service\Companion\Companion;
use App\Service\Companion\CompanionMarket;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @package App\Controller
 */
class CompanionMarketController extends Controller
{
    /** @var CompanionMarket */
    private $companionMarket;
    /** @var Companion */
    private $companion;
    
    public function __construct(Companion $companion, CompanionMarket $companionMarket)
    {
        $this->companion = $companion;
        $this->companionMarket = $companionMarket;
    }
    
    /**
     * @Route("/market/{server}/items/{itemId}")
     * @Route("/market/{server}/item/{itemId}")
     */
    public function item(string $server, int $itemId)
    {
        return $this->json(
            $this->companionMarket->get($server, $itemId)
        );
    }
    
    /**
     * @Route("/v2/market/search")
     */
    public function test()
    {
        return $this->json(
            $this->companionMarket->search()
        );
    }
}
