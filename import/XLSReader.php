<?php
namespace marianojwl\XLSReader {
  class Sheet {
    protected $index;
    protected $name;
    protected $rows;
    protected $template;
    protected $dataSets;
    protected $metaData;

    public function name() { return $this->name; }
    public function index() { return $this->index; }
    public function rows() { return $this->rows; }

    private function getParsedValue($value, $type="string", $regexMatch=null, $givenFormat=null, $outputFormat=null, $allMatchesMustBeEqual=false) {
      $rawValue = $value;
      if ($regexMatch) {
        preg_match('/'.$regexMatch.'/', $value, $matches);
        if (empty($matches)) {
          throw new \Exception("\"$rawValue\" no pudo ser validado con $regexMatch");
        }
        if($allMatchesMustBeEqual) {
          for($m = 2; $m < count($matches); $m++){
            if($matches[$m] != $matches[1]){
              throw new \Exception($matches[$m] . " no es igual a " . $matches[1]);
            }
          }

        }
        $rawValue = $matches[1] ?? null;
      }
      switch ($type) {
        case 'number':
          $rawValue = floatval($rawValue);
          break;
        case 'date':
          try {
            $rawValue = \DateTime::createFromFormat($givenFormat, $rawValue)->format($outputFormat);
          } catch (\Throwable  $e) {
            throw new \Exception("Error parsing date: " . $e->getMessage());
          }
          break;
        default:
          break;
      }
      return $rawValue;
    }

    private function getMetaWithValue($meta){
      $md = $meta;
      $rows = $this->rows;
      $md['value'] = $this->getParsedValue(
        $rows[$meta['row']-1][$meta['column']-1],
        $meta['type'],
        $meta['regexMatch'] ?? null,
        $meta['givenFormat'] ?? null,
        $meta['outputFormat'] ?? null,
        $meta['allMatchesMustBeEqual'] ?? false
      );
      return $md;
    }

    private function getSheetLevelMetaDataStructureFromTemplate() {
      $template = $this->template;
      $metaData = array_filter($template['setMarkers']['metaData'], function($meta) {
        return isset($meta['column']) && isset($meta['row']);
      });

      $md = [];

      foreach($metaData as $meta) {
        $md[$meta['key']] = $this->getMetaWithValue($meta);
      }
      
      return $md;
    }

    private function getSetLevelMetaDataStructureFromTemplate() {
      $template = $this->template;
      $metaData = array_filter($template['setMarkers']['metaData'], function($meta) {
        return isset($meta['setColumn']) && isset($meta['setRow']);
      });

      return $metaData;
    }

    public function __construct($template, $index, $name, $rows) {
      $this->template = $template;
      $this->rows = $rows;
      $this->index = $index;
      $this->name = $name;
      $this->dataSets = [];
      $this->metaData = $this->getSheetLevelMetaDataStructureFromTemplate();
    }

    private function isBegginingOfSet($row){
      $regexMatch = $this->template['setMarkers']['start']['regexMatch'];
      $column = $this->template['setMarkers']['start']['column'] - 1;
      return isset($row[$column]) && preg_match('/'.$regexMatch.'/', $row[$column]);
    }

    private function isEndOfSet($row){
      $regexMatch = $this->template['setMarkers']['end']['regexMatch'];
      $column = $this->template['setMarkers']['end']['column'] - 1;
      return isset($row[$column]) && preg_match('/'.$regexMatch.'/', $row[$column]);
    }

    private function getParsedRow($row) {
      $newRow = [];
      $keys = $this->template['setMarkers']['values']['keys'];
      foreach($keys as $key) {
        $newRow[$key['name']] = $this->getParsedValue(
          $row[$key['column']-1],
          $key['type'] ?? 'string',
          $key['regexMatch'] ?? null,
          $key['givenFormat'] ?? null,
          $key['outputFormat'] ?? null,
          $key['allMatchesMustBeEqual'] ?? false
        );
      }
      return $newRow;
    }

    protected function iterate() {
      $totalRows = count($this->rows);
      $newSet = null;
      $inDataArea = false;
      $startOffset = $this->template['setMarkers']['values']['startOffset'];
      $endOffset = $this->template['setMarkers']['values']['endOffset'];
      $setMetaDataStructure = $this->getSetLevelMetaDataStructureFromTemplate();
      $j = -1;
      foreach($this->rows as $i => $row) {

        // CHECK IF BEGGINING OF SET
        if($this->isBegginingOfSet($row)){
          $newSet = [];
          $newSetMetaData = array_map(function($meta) {
            return [$meta['key'] => null];
          }, $setMetaDataStructure);
          $newSetMetaData = array_merge(...$newSetMetaData);
          $j = -1;
        }
        
        $j++;

        // CHECK IF END OF SET
        if($this->isEndOfSet($row)){
          if($newSet) {
            $this->dataSets[] = $newSet;
          }
          $newSet = null;
          $inDataArea = false;
        }

        // CHECK IF IN DATA AREA
        if($newSet !== null && $j > ($startOffset-1) ){
          $inDataArea = true;
        }

        // CHECK IF IN META DATA AREA
        if($newSet !== null && !$inDataArea ){

          foreach($setMetaDataStructure as $key){
            if($newSetMetaData[$key['key']]) {
              continue;
            } 
            if($key['setRow'] == $j+1){
              $newSetMetaData[$key['key']] = $this->getParsedValue(
                $row[$key['setColumn']-1],
                $key['type'] ?? 'string',
                $key['regexMatch'] ?? null,
                $key['givenFormat'] ?? null,
                $key['outputFormat'] ?? null,
                $key['allMatchesMustBeEqual'] ?? false
              );
            }
          }
        }

        // IF IN SET, ADD DATA
        if($inDataArea){
          $newRow = $newSetMetaData;

          // ADD META DATA
          foreach($this->metaData as $key => $meta) {
            $newRow[$key] = $meta['value'];
          }

          // ADD VALUES
          $newRow = array_merge($newRow, $this->getParsedRow($row));
          // ADD NEW ROW TO SET
          array_push($newSet, $newRow);
        }
      }

    }
    public function getData() {
      $this->iterate();
      
      if(@$this->template['mergeSets']??false){
        $this->dataSets = array_merge(...$this->dataSets);
      }
        
      return $this->dataSets;
    }

  }
  class XLSReader {
    protected $xlsPath;
    protected $parsedXls;
    protected $template;
    protected $sheets;

    public function __construct($xlsPath, $template) {
      $this->xlsPath = $xlsPath;
      $this->template = $template;
      $this->parsedXls = \Shuchkin\SimpleXLS::parse($xlsPath);
      $sheetNames = $this->parsedXls->sheetNames();
      foreach ($sheetNames as $index => $name) {
        $sheetTemplate = array_filter($this->template['sheets'], function($sheet) use ($name) {
          return $sheet['name'] == $name;
        });
        if (empty($sheetTemplate)) {
          continue;
        }
        $this->sheets[$index] = new Sheet($sheetTemplate[0], $index, $name, $this->parsedXls->rows($index));
      }
    }

    public function getAsJson(){
      return json_encode($this->getData());
    }
    public function getData(){
      $data = [];
      foreach ($this->sheets as $sheet) {
        //$data[$sheet->name()] = $sheet->getData();
        $data = array_merge($data, $sheet->getData());
      }
      return $data;
    }
  }
  
}