<?php

namespace App\Service\Data;

use App\Service\SaintCoinach\SaintCoinach;
use League\Csv\Reader;
use League\Csv\Statement;

/**
 * Reads the game files
 */
class FileReader extends DataHelper
{
    const FOREIGN_REMOVALS = [
        '<Emphasis>',   '</Emphasis>',  '<Emphasis/>',
        '<Indent>',     '</Indent>',    '<Indent/>',
        '<SoftHyphen/>'
    ];
    
    public static function open(string $filename, bool $isRaw)
    {
        return $isRaw
            ? self::handleRaw($filename)
            : self::handleGameData($filename);
    }

    /**
     * Handles the game data files, eg: Items.en.csv
     */
    public static function handleGameData(string $filename)
    {
        $root = SaintCoinach::directory();
        $filenameStructure = "{$root}/raw-exd-all/%s.%s.csv";
        $filenameList = new \stdClass();

        // build a list of multi-language filenames
        foreach(['en','de','fr','ja','chs'] as $language) {
            $filenameList->{$language} = sprintf($filenameStructure, $filename, $language);
        }

        // parse main csv file
        [$columns, $types, $data] = self::parseCsvFile($filenameList->chs);

        // append on: English, German, French and Japanese
        foreach(['en', 'de', 'fr', 'ja'] as $language) {
            $langFilename = $filenameList->{$language};
            if (file_exists($langFilename)) {
                $data = self::parseLanguageCsvFile($language, $langFilename, $data, $columns, $types);
            }
        }

        return $data;
    }

    /**
     * Handles the raw value files, eg: ParamGrow.csv
     */
    public static function handleRaw(string $filename)
    {
        $root = SaintCoinach::directory();
        $filenameStructure = "{$root}/raw-exd-all/%s.csv";
        $filename = sprintf($filenameStructure, $filename);

        [$columns, $types, $data] = self::parseCsvFile($filename);

        return $data;
    }

    /**
     * Parses a CSV file and provides:
     * - Columns
     * - Types
     * - Data
     */
    public static function parseCsvFile($filename)
    {
        $csv = Reader::createFromPath($filename);

         // get columns
        $stmt = (new Statement())->offset(1)->limit(1);
        $columns = $stmt->process($csv)->fetchOne();
        $columns = self::getRealColumnNames($filename, $columns);

        // get types
        $stmt = (new Statement())->offset(2)->limit(1);
        $types = $stmt->process($csv)->fetchOne();

        // get data
        $stmt = (new Statement())->offset(3);

        $data = [];
        foreach($stmt->process($csv)->getRecords() as $record) {
            $id = $record[0];
            
            // handle column names
            $newRecords = [];
            foreach($record as $offset => $value) {
                $columnName = $columns[$offset];

                // remove columns with no names
                if (empty($columnName)) {
                    // unset and ignore
                    unset($record[$offset]);
                    continue;
                } else if ($value > 2147483647) {
                    // not dealing with this shit!
                    // this is likely a wrong mapper, eg uint instead of a int64
                    $value = null;
                } else if (strtoupper($value) === 'TRUE') {
                    $value = 1;
                } else if (strtoupper($value) === 'FALSE') {
                    $value = 0;
                } else if ($types[$offset] == 'Image') {
                    // maintain an ID record
                    $newRecords[] = [
                          'Image',
                          $columnName .'ID',
                          $value
                    ];
                    
                    // convert icon
                    $value = DataHelper::getImagePath($value);
                } else if ($types[$offset] == 'str') {
                    $columnName = "{$columnName}_chs";
                    
                    // fix new lines (broke around 30th May 2018)
                    $value = str_ireplace("\r", "\n", $value);
                }
                
                $record[$columnName] = $value;
                unset($record[$offset]);
            }
    
            // remove foreign stuff
            $record = str_ireplace(self::FOREIGN_REMOVALS, null, $record);
            
            // add new records
            foreach ($newRecords as $nr) {
                [$newType, $newColumnName, $newValue] = $nr;
                
                $types[] = $newType;
                $columns[] = $newColumnName;
                $record[$newColumnName] = $newValue;
            }
      
            ksort($record);
            $data[$id] = $record;
            unset($record);
        }

        unset($csv, $stmt);
        return [
            $columns,
            $types,
            $data
        ];
    }

    /**
     * Parses a SUB (language specific) CSV filename and returns the
     * updated data set
     */
    public static function parseLanguageCsvFile($language, $filename, $data, $columns, $types)
    {
        $csv = Reader::createFromPath($filename);
        $stmt = (new Statement())->offset(3);

        foreach($stmt->process($csv)->getRecords() as $record) {
            $id = $record[0];

            foreach($types as $offset => $type) {
                // process all strings
                if ($type == 'str') {
                    // ignore empty ones
                    if (strlen($columns[$offset]) == 0) {
                        continue;
                    }
                    
                    $columnName = "{$columns[$offset]}_{$language}";
                    $value = $record[$offset];
    
                    // fix new lines (broke around 30th May 2018)
                    $value = str_ireplace("\r", "\n", $value);
                    
                    $data[$id][$columnName] = $value;
                }
            }
    
            // remove foreign stuff
            $data[$id] = str_ireplace(self::FOREIGN_REMOVALS, null, $data[$id]);

            // sort
            ksort($data[$id]);
        }

        unset($csv, $stmt);
        return $data;
    }
}
