<?
/* --- SRV --- */
final class SrvRecord extends DnsRdata
{
  public function __construct(
    public int  $priority,
    public int  $weight,
    public int  $port,
    public string $target,
  ) {}

  public function serialize(): string
  {
    return pack('nnn', $this->priority, $this->weight, $this->port) . NameCodec::encode($this->target);
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    if ($rdLen < 7)
      throw new RuntimeException("Некорректная длина SRV-записи: $rdLen");
    
    ['priority' => $p, 'weight' => $w, 'port' => $pt] =
      unpack('npriority/nweight/nport', $msg, $off);
    [$target] = NameCodec::decode($msg, $off + 6);
    return new static($p, $w, $pt, $target);
  }

  public function __toString(): string
  {
    return "{$this->priority} {$this->weight} {$this->port} {$this->target}";
  }

  static function test(TestRunner $t)
  {
    $srv = new SrvRecord(1, 5, 5061, 'sip.example.com');
    $srv2 = SrvRecord::deserialize($srv->serialize(), 0, strlen($srv->serialize()));
    $t->eq([$srv2->priority, $srv2->weight, $srv2->port, $srv2->target], [1, 5, 5061, 'sip.example.com'], 'SrvRecord: roundtrip');
  }

//****************************************************************
// Json

  public function toJson(): string
  {
    return sprintf('%d %d %d %s',
      $this->priority, $this->weight, $this->port,
      NameCodec::toPresentation($this->target));
  }

  public static function fromJson(string $data): static
  {
    $p = preg_split('/\s+/', trim($data));
    if (count($p) !== 4)
      throw new RuntimeException("Некорректный SRV: $data");
    
    return new static((int)$p[0], (int)$p[1], (int)$p[2], NameCodec::fromPresentation($p[3]));
  }

//****************************************************************
}
