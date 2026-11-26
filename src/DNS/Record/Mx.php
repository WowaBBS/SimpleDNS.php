<?
/* --- MX --- */
final class MxRecord extends DnsRdata
{
  public function __construct(
    public int    $pref     ,
    public string $exchange ,
  ) {}

  public function writeTo(DnsWriter $w): void
  {
    $w->writeU16($this->pref);
    $w->writeName($this->exchange);
  }
  
  public function serialize(): string
  {
    return pack('n', $this->pref) . NameCodec::encode($this->exchange);
  }

  public function serializeWith(NameTable $table, string &$buf): void
  {
    $buf .= pack('n', $this->pref);
    NameCodec::encodeWith($this->exchange, $table, $buf);
  }
  
  public static function fromReader(DnsReader $r): static
  {
    if ($r->remaining() < 3)
      throw new RuntimeException('Некорректная длина MX-записи: '.$r->remaining());
    
    $pref = $r->readU16();
    $exchange = $r->readName();
    return new static($pref, $exchange);
  }

  public function __toString(): string { return "{$this->pref} {$this->exchange}"; }

  static function test(TestRunner $t)
  {
    $mx = new MxRecord(10, 'mail.example.com');
    $mx2 = MxRecord::fromReader(DnsReader::of($mx->serialize()));
    $t->eq([$mx2->pref, $mx2->exchange], [10, 'mail.example.com'], 'MxRecord: roundtrip');
  }

//****************************************************************
// Json

  public function toText(): string
  {
    return $this->pref . ' ' . NameCodec::toPresentation($this->exchange);
  }

  public static function fromText(string $data): static
  {
    if (!preg_match('/^(\d+)\s+(\S+)$/', trim($data), $m))
      throw new RuntimeException("Некорректный MX: $data");
    
    return new static((int)$m[1], NameCodec::fromPresentation($m[2]));
  }

//****************************************************************
}
