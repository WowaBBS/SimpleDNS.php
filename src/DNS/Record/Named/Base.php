<?
/* --- NS / CNAME / PTR: RDATA - одно имя --- */
abstract class NamedRdata extends DnsRdata
{
  public function __construct(public string $target) {}

  public function writeTo(DnsWriter $w): void
  {
    $w->writeName($this->target);
  }
  
  public function serialize(): string
  {
    return NameCodec::encode($this->target);
  }

  public function serializeWith(NameTable $table, string &$buf): void
  {
    NameCodec::encodeWith($this->target, $table, $buf);
  }
  
  public static function fromReader(DnsReader $r): static
  {
    $name = $r->readName();
    return new static($name);
  }

  public function __toString(): string { return $this->target; }

  static function test(TestRunner $t)
  {
    // MX, где exchange указывает на имя из QUESTION: CNAME-запись с указателем на смещение 12
    // буфер: [12 байт заголовка][question name "example.com"][...][CNAME rdata с указателем C00C]
    $msg = str_repeat("\0", 12)
       . "\x07example\x03com\x00" // имя в смещении 12
       . "\x07target"         // не важная часть
       . "\xC0\x0C"           // CNAME rdata: указатель на "example.com"
    ;
    $cname = CnameRecord::fromReader(DnsReader::of($msg, -2));
    $t->eq($cname->target, 'example.com', 'RdataName: указатель из RDATA в вопрос разрешается');
  }

//****************************************************************
// Json

  public function toText(): string
  {
    return NameCodec::toPresentation($this->target);
  }

  public static function fromText(string $data): static
  {
    return new static(NameCodec::fromPresentation(trim($data)));
  }

//****************************************************************
}
