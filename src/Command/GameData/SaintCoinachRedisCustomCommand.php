<?php

namespace App\Command\GameData;

use App\Command\CommandHelperTrait;
use App\Service\DataCustom\Achievement;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SaintCoinachRedisCustomCommand extends Command
{
    use CommandHelperTrait;

    protected function configure()
    {
        $this
            ->setName('SaintCoinachRedisCustomCommand')
            ->setDescription('Build custom data edits')
            ->addArgument('content_name', InputArgument::OPTIONAL, 'Run a specific content name')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setSymfonyStyle($input, $output);
        $this->title('CUSTOM DATA MAPPER');
        $this->startClock();
        
        $filelist = scandir(__DIR__ . '/../../Service/DataCustom');
        
        $force = $input->getArgument('content_name');
        
        $customClassList = [];
        foreach ($filelist as $file) {
            if (substr($file, -4) !== '.php') {
                continue;
            }

            $class = substr($file, 0, -4);
            
            // skip content_name
            if ($force && $force !== $class) {
                continue;
            }
            
            // this one done on its own due to memory issues
            if (!$force && ($class == 'Quest' || $class == 'SkillDescriptions')) {
                continue;
            }
            
            $class = "\\App\\Service\\DataCustom\\{$class}";
            
            /** @var Achievement $class */
            $class = new $class();
            $customClassList[$class::PRIORITY][] = $class;
        }

        // sort class list by priority
        ksort($customClassList);

        echo 'Classes to run:';
        echo PHP_EOL;
        foreach ($customClassList as $priority => $classes) {
            foreach ($classes as $class) {
                echo get_class($class) . PHP_EOL;
            }
        }
        
        $has_error = false;

        // process each custom data
        foreach ($customClassList as $priority => $classes) {
            foreach ($classes as $class) {
                try {
                    $class->init($this->io)->handle();
                } catch (\Exception $ex) {
                    $has_error = true;
                    $this->io->error("Error: {$ex->getMessage()}");
                    echo $ex;
                }
            }
        }
        
        $this->endClock();
        if ($has_error) {
            die(1);
        }
    }
}
