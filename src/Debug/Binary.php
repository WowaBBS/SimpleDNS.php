<?
function hexs(string $bin): string
{
  return strtoupper(implode(' ', str_split(bin2hex($bin), 2)));
}

function binary($text) { return new Binary($text)->toHexs(); }

class Binary
{
  var $lines = [];
  
  function __construct($text)
  {
    $res = [];
    foreach (explode("\n", $text) as $line)
    {
      $item = [
        'line' => $line,
      ];
      
      if (preg_match('/^(.*?)((?:\\;|\\/|#).*)$/', $line, $matches))
      {
        $item['comment'] = $matches[2];
        $line            = $matches[1];
      }
      
      preg_match_all('/\w+/', $line, $matches, PREG_OFFSET_CAPTURE);
      $format = 'hex';
      $data = [];
      foreach ($matches[0] as [$word, $offset])
        $data[] = [
          $word   ,
          $format ,
          $offset , // позиция (индекс) в исходной строке
        ];
        
      $item['data'] = $data;
      
      $res []= $item;
    }
    $this->lines = $res;
  }
  
  function toHexs()
  {
    $result = [];
    foreach ($this->lines as $row => $line)
      foreach ($line['data'] as [$word, $format, $col])
        $result[]= $word; // Only hex now
    $result = join($result);
    return strtoupper(implode(' ', str_split($result, 2)));
  }
}