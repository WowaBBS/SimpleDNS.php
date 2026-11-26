<?
/* --- CAA --- */
final class CaaRecord extends DnsRdata
{
  public function __construct(
    public int  $flags,
    public string $tag,
    public string $value,
  ) {}

  public function serialize(): string
  {
    if (strlen($this->tag) > 255)
      throw new InvalidArgumentException('CAA tag длиннее 255 байт');
    
    return chr($this->flags) . chr(strlen($this->tag)) . $this->tag . $this->value;
  }

  public static function fromReader(DnsReader $r): static
  {
    if ($r->remaining() < 2)
      throw new RuntimeException('Некорректная длина CAA-записи: '.$r->remaining());
    
    $flags  = $r->readU8();
    $tagLen = $r->readU8();
    if ($tagLen > $r->remaining())
      throw new RuntimeException('CAA tag выходит за границы RDATA');
    
    return new static($flags, $r->readBytes($tagLen), $r->readBytes($r->remaining()));
  }

  public function __toString(): string
  {
    return sprintf('%d %s "%s"', $this->flags, $this->tag, $this->value);
  }

  static function test(TestRunner $t)
  {
    $caa = new CaaRecord(0, 'issue', 'letsencrypt.org');
    $caa2 = CaaRecord::fromReader(DnsReader::of($caa->serialize()));
    $t->eq($caa2->flags, 0, 'CaaRecord: roundtrip flags');
    $t->eq($caa2->tag, 'issue', 'CaaRecord: roundtrip tag');
    $t->eq($caa2->value, 'letsencrypt.org', 'CaaRecord: roundtrip value');
  }

//****************************************************************
// Json

  public function toJson(): string
  {
    return sprintf('%d %s "%s"', $this->flags, $this->tag, StrCodec::escape($this->value));
  }

  public static function fromJson(string $data): static
  {
    if (!preg_match('/^(\d+)\s+(\S+)\s+"((?:[^"\\\\]|\\\\.)*)"$/', trim($data), $m))
      throw new RuntimeException("Некорректный CAA: $data");
    
    return new static((int)$m[1], $m[2], StrCodec::unescape($m[3]));
  }
//****************************************************************

  static function testJson(TestRunner $t)
  {
    // CAA roundtrip
    $caaJ = CaaRecord::fromJson('0 issue "letsencrypt.org"');
    $t->eq([$caaJ->flags, $caaJ->tag, $caaJ->value], [0, 'issue', 'letsencrypt.org'], 'CaaRecord::fromJson');
  }
}
