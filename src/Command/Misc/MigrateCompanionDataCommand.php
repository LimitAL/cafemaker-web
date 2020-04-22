<?php

namespace App\Command\Misc;

use App\Common\Game\GameServers;
use App\Common\Service\Redis\Redis;
use App\Service\Companion\CompanionMarket;
use App\Service\Companion\CompanionMarketDoc;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCompanionDataCommand extends Command
{
    /** @var CompanionMarket */
    private $cm;
    /** @var CompanionMarketDoc */
    private $cmd;
    
    public function __construct(CompanionMarket $cm, CompanionMarketDoc $cmd, ?string $name = null)
    {
        $this->cm = $cm;
        $this->cmd = $cmd;
        
        parent::__construct($name);
    }
    
    protected function configure()
    {
        $this
            ->setName('MigrateCompanionDataCommand')
            ->setDescription('Downloads a bunch of info from Lodestone, including icons.')
        ;
    }
    
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $console = new ConsoleOutput();
        $console = $console->section();

        $ids     = Redis::Cache()->get("ids_Item");
        $total   = count((array)$ids);
        $count   = 0;

        $servers = GameServers::LIST;
        foreach (GameServers::MARKET_OFFLINE as $serverId) {
            unset($servers[$serverId]);
        }

        $etaTot  = $total * count($servers);
        $etaArr  = [];

        $start   = time();
        foreach ($ids as $itemId) {
            $count++;

            foreach ($servers as $serverId => $serverName) {
                $doc = $this->cm->get($serverId, $itemId, true);
                $this->cmd->save($serverId, $itemId, $doc);
                $etaTot--;

                $etaArr[] = time() - $start;
                $start    = time();

                array_splice($etaArr, 200);

                $avg     = array_sum($etaArr) / count($etaArr);
                $avgTime = $etaTot * $avg;
                $finish  = date('Y-m-d H:i:s', time() + $avgTime);

                $console->overwrite("Convert item: {$itemId} - {$count}/{$total} - {$finish} - {$serverName}");
            }
        }

        $console->overwrite("Done!");
    }
}
