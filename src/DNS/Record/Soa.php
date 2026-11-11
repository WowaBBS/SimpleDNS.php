<?
/* --- SOA --- */
final class SoaRecord extends DnsRdata
{
  public function __construct(
    public string $mname   ,
    public string $rname   ,
    public int    $serial  ,
    public int    $refresh ,
    public int    $retry   ,
    public int    $expire  ,
    public int    $minimum ,
  ) {}

  public function serialize(): string
  {
    return 
       NameCodec::encode($this->mname) 
      .NameCodec::encode($this->rname)
      .pack('N5', $this->serial, $this->refresh, $this->retry, $this->expire, $this->minimum);
  }

  public static function deserialize(string $msg, int $off, int $rdLen): static
  {
    [$mname, $off] = NameCodec::decode($msg, $off);
    [$rname, $off] = NameCodec::decode($msg, $off);
    $n = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nmin', $msg, $off);
    return new static($mname, $rname, $n['serial'], $n['refresh'], $n['retry'], $n['expire'], $n['min']);
  }

  public function __toString(): string
  {
    return sprintf('%s %s serial=%u refresh=%u retry=%u expire=%u min=%u',
      $this->mname, $this->rname, $this->serial, $this->refresh, $this->retry, $this->expire, $this->minimum);
  }

  static function test(TestRunner $t)
  {
    $soa = new SoaRecord('ns1.example.com', 'admin.example.com', 2024010101, 7200, 900, 1209600, 3600);
    $soa2 = SoaRecord::deserialize($soa->serialize(), 0, strlen($soa->serialize()));
    $t->eq($soa2->serial, 2024010101, 'SoaRecord: roundtrip serial');
    $t->eq($soa2->minimum, 3600, 'SoaRecord: roundtrip minimum');
  }

//****************************************************************
// Json

  public function toJson(): string
  {
    return implode(' ', [
      NameCodec::toPresentation($this->mname),
      NameCodec::toPresentation($this->rname),
      $this->serial, $this->refresh, $this->retry, $this->expire, $this->minimum,
    ]);
  }

  public static function fromJson(string $data): static
  {
    $p = preg_split('/\s+/', trim($data));
    if (count($p) !== 7) {
      throw new RuntimeException("Некорректный SOA: $data");
    }
    return new static(
      NameCodec::fromPresentation($p[0]),
      NameCodec::fromPresentation($p[1]),
      (int)$p[2], (int)$p[3], (int)$p[4], (int)$p[5], (int)$p[6],
    );
  }

//****************************************************************
}
