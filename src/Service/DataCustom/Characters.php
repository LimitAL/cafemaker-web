<?php

namespace App\Service\DataCustom;

use App\Service\Content\ContentHash;
use App\Service\Content\ManualHelper;
use App\Common\Service\Redis\Redis;

class Characters extends ManualHelper
{
    const PRIORITY = 50;
    
    private $keys = [];
    
    public function handle()
    {
        // populate game data
        $this->populate('Companion', 'Name_chs');
        $this->populate('Mount', 'Name_chs');
        $this->populate('Race', 'Name_chs', 'NameFemale_chs');
        $this->populate('Tribe', 'Name_chs', 'NameFemale_chs');
        $this->populate('Title', 'Name_chs', 'NameFemale_chs');
        $this->populate('GrandCompany', 'Name_chs');
        $this->populate('GuardianDeity', 'Name_chs');
        $this->populate('Town', 'Name_chs');
        $this->populate('BaseParam', 'Name_chs');
        $this->populate('GCRankGridaniaFemaleText', 'Name_chs');
        $this->populate('GCRankGridaniaMaleText', 'Name_chs');
        $this->populate('GCRankLimsaFemaleText', 'Name_chs');
        $this->populate('GCRankLimsaMaleText', 'Name_chs');
        $this->populate('GCRankUldahFemaleText', 'Name_chs');
        $this->populate('GCRankUldahMaleText', 'Name_chs');
    
        // special ones
        $this->populateParamGrow();
        $this->populateMateria();
        $this->populateItems();
        $this->populateDyes();
        
        Redis::Cache()->set('character_keys', $this->keys, self::REDIS_DURATION);
    }
    
    /**
     * Populate common data
     */
    protected function populate($contentName, $column, $femaleColumn = false)
    {
        $this->io->text(__METHOD__ . " {$contentName}");
        
        $data = [];
        foreach (Redis::Cache()->get("ids_{$contentName}") as $id) {
            $content = Redis::Cache()->get("xiv_{$contentName}_{$id}");
       
            $hash = ContentHash::hash($content->{$column});
            $data[$hash] = $content->ID;
            
            if ($femaleColumn) {
                $hash = ContentHash::hash($content->{$femaleColumn});
    
                // set hash if no hash already set. If the female name is the same
                // as the male name then the hash would be the same and the content id would be as well.
                if (empty($data[$hash])) {
                    $data[$hash] = $content->ID;
                }
            }
        }
        
        Redis::Cache()->set("character_{$contentName}", $data, self::REDIS_DURATION);
        $this->keys[] = $contentName;
    }
    
    /**
     * Cache the EXP per level
     */
    private function populateParamGrow()
    {
        $this->io->text(__METHOD__);
        
        $data = [];
        foreach (Redis::Cache()->get("ids_ParamGrow") as $id) {
            $content = Redis::Cache()->get("xiv_ParamGrow_{$id}");
    
            // don't care about zero exp stuff
            if ($content->ExpToNext == 0) {
                break;
            }
            
            $data[$content->ID] = $content->ExpToNext;
        }
        
        Redis::Cache()->set("character_ParamGrow", $data, self::REDIS_DURATION);
        $this->keys[] = 'ParamGrow';
    }
    
    /**
     * Cache the Materia names
     */
    private function populateMateria()
    {
        $this->io->text(__METHOD__);
        
        $data = [];
        foreach (Redis::Cache()->get("ids_Item") as $id) {
            $content = Redis::Cache()->get("xiv_Item_{$id}");
            
            // if it's a material item
            if (isset($content->ItemUICategory->ID) && $content->ItemUICategory->ID == 58) {
                $hash = ContentHash::hash($content->Name_chs);
                $data[$hash] = $content->ID;
            }
        }
        
        Redis::Cache()->set("character_Materia", $data, self::REDIS_DURATION);
        $this->keys[] = 'Materia';
    }
    
    /**
     * Cache equipment items
     */
    private function populateItems()
    {
        $this->io->text(__METHOD__);
    
        $data = [];
        foreach (Redis::Cache()->get("ids_Item") as $id) {
            $content = Redis::Cache()->get("xiv_Item_{$id}");
    
            // only stuff that has a class/job category
            if (isset($content->ClassJobCategory->ID)) {
                $hash = ContentHash::hash($content->Name_chs);
                $data[$hash] = $content->ID;
            }
        }
    
        Redis::Cache()->set("character_Equipment", $data, self::REDIS_DURATION);
        $this->keys[] = 'Equipment';
    }
    
    /**
     * Cache dyes
     */
    private function populateDyes()
    {
        $this->io->text(__METHOD__);
    
        $data = [];
        foreach (Redis::Cache()->get("ids_Item") as $id) {
            $content = Redis::Cache()->get("xiv_Item_{$id}");
            
            // if it's a material item
            if (isset($content->ItemUICategory->ID) && $content->ItemUICategory->ID == 55) {
                $hash = ContentHash::hash($content->Name_chs);
                $data[$hash] = $content->ID;
            }
        }
    
        Redis::Cache()->set("character_Dye", $data, self::REDIS_DURATION);
        $this->keys[] = 'Dye';
    }
}
