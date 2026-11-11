<?
/* --- MX --- */
final class MxRecord extends DnsRdata
{
  public function __construct(
    public int    $pref     ,
    public string $exchange ,
  ) {}

  public function serialize(): string
  {
    return pack('n', $this->pref) . NameCodec::encode($this->exchange);
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    if ($rdLen < 3)
      throw new RuntimeException("Некорректная длина MX-записи: $rdLen");
    
    $pref = unpack('n', $msg, $off)[1];
    [$exchange] = NameCodec::decode($msg, $off + 2);
    return new static($pref, $exchange);
  }

  public function __toString(): string { return "{$this->pref} {$this->exchange}"; }

  static function test(TestRunner $t)
  {
    $mx = new MxRecord(10, 'mail.example.com');
    [$mx2] = [MxRecord::deserialize($mx->serialize(), 0, strlen($mx->serialize()))];
    $t->eq([$mx2->pref, $mx2->exchange], [10, 'mail.example.com'], 'MxRecord: roundtrip');
  }

//****************************************************************
// Json

  public function toJson(): string
  {
    return $this->pref . ' ' . NameCodec::toPresentation($this->exchange);
  }

  public static function fromJson(string $data): static
  {
    if (!preg_match('/^(\d+)\s+(\S+)$/', trim($data), $m))
      throw new RuntimeException("Некорректный MX: $data");
    
    return new static((int)$m[1], NameCodec::fromPresentation($m[2]));
  }

//****************************************************************
}
