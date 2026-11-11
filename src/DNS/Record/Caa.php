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

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    if ($rdLen < 2)
      throw new RuntimeException("Некорректная длина CAA-записи: $rdLen");
    
    $flags  = ord($msg[$off]);
    $tagLen = ord($msg[$off + 1]);
    if ($tagLen + 2 > $rdLen)
      throw new RuntimeException('CAA tag выходит за границы RDATA');
    
    $tag   = substr($msg, $off + 2, $tagLen);
    $value = substr($msg, $off + 2 + $tagLen);
    return new static($flags, $tag, $value);
  }

  public function __toString(): string
  {
    return sprintf('%d %s "%s"', $this->flags, $this->tag, $this->value);
  }

  static function test(TestRunner $t)
  {
    $caa = new CaaRecord(0, 'issue', 'letsencrypt.org');
    $caa2 = CaaRecord::deserialize($caa->serialize(), 0, strlen($caa->serialize()));
    $t->eq($caa2->flags, 0, 'CaaRecord: roundtrip flags');
    $t->eq($caa2->tag, 'issue', 'CaaRecord: roundtrip tag');
    $t->eq($caa2->value, 'letsencrypt.org', 'CaaRecord: roundtrip value');
  }
}
