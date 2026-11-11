<?
/* --- TXT --- */
final class TxtRecord extends DnsRdata
{
  /** @param string[] $texts */
  public function __construct(public array $texts) {}

  public function serialize(): string
  {
    $out = '';
    foreach ($this->texts as $t)
    {
      if ($t === '')
      {
        $out .= "\0";
        continue;
      }
      foreach (str_split($t, 255) as $chunk) // строка длиннее 255 байт режется на куски
        $out .= chr(strlen($chunk)) . $chunk;
    }
    return $out;
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    $texts = [];
    $p = 0;
    while ($p < $rdLen)
    {
      $l = ord($msg[$off + $p]);
      $texts[] = substr($msg, $off + $p + 1, $l);
      $p += 1 + $l;
    }
    return new static($texts);
  }

  public function __toString(): string { return implode('', $this->texts); }

  static function test(TestRunner $t)
  {
    $txt = new TxtRecord(['v=spf1 include:_spf.example.com ~all']);
    $txt2 = TxtRecord::deserialize($txt->serialize(), 0, strlen($txt->serialize()));
    $t->eq((string)$txt2, 'v=spf1 include:_spf.example.com ~all', 'TxtRecord: roundtrip одной строки');

    // TXT длиннее 255 байт: режется на куски, при повторной сериализации байты совпадают
    $long     = str_repeat('x', 300);
    $txtLong  = new TxtRecord([$long]);
    $wire     = $txtLong->serialize();
    $txtLong2 = TxtRecord::deserialize($wire, 0, strlen($wire));
    $t->eq(strlen($wire), 255 + 1 + 45 + 1, 'TxtRecord: 300 символов -> два куска 255+45');
    $t->eq($txtLong2->serialize(), $wire, 'TxtRecord: повторная сериализация байт в байт');
    $t->eq((string)$txtLong2, $long, 'TxtRecord: сращённый текст равен исходному');

    // TXT из нескольких строк
    $txtMulti = new TxtRecord(['a', 'b']);
    $txtMulti2 = TxtRecord::deserialize($txtMulti->serialize(), 0, strlen($txtMulti->serialize()));
    $t->eq($txtMulti2->texts, ['a', 'b'], 'TxtRecord: несколько строк roundtrip');
  }
}
