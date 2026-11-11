<?
/* --- NS / CNAME / PTR: RDATA - одно имя --- */
abstract class NamedRdata extends DnsRdata
{
  public function __construct(public string $target) {}

  public function serialize(): string
  {
    return NameCodec::encode($this->target);
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    [$name] = NameCodec::decode($msg, $off);
    return new static($name);
  }

  public function __toString(): string { return $this->target; }

  static function test(TestRunner $t)
  {
    // MX, где exchange указывает на имя из QUESTION: CNAME-запись с указателем на смещение 12
    // буфер: [12 байт заголовка][question name "example.com"][...][CNAME rdata с указателем C00C]
    $msg = str_repeat("\0", 12)
       . "\x07example\x03com\x00"   // имя в смещении 12
       . "\x07target"         // не важная часть
       . "\xC0\x0C"           // CNAME rdata: указатель на "example.com"
    ;
    [$cname] = CnameRecord::deserialize($msg, 0 + strlen($msg) - 2, 2);
    $t->eq($cname->target, 'example.com', 'RdataName: указатель из RDATA в вопрос разрешается');
  }
}
