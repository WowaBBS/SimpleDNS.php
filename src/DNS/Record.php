<?
/* ============================================================
 * Ресурсная запись (общая обёртка name/type/class/ttl/rdata)
 * ============================================================ */

final class DnsRecord
{
  public function __construct(
    public string    $name  ,
    public int       $type  ,
    public int       $class = 1,
    public int       $ttl   = 0,
    public ?DnsRdata $rdata = null,
  ) {}

  /** Фабрика OPT-записи для EDNS0 */
  public static function opt(int $udpSize = 4096, bool $dnssec = false): self
  {
    return new self('', 41, $udpSize, 0, new OptRecord($udpSize, 0, $dnssec));
  }

  public function serialize(): string
  {
    $rdata = $this->rdata?->serialize() ?? '';

    // у OPT класс-поле несёт UDP-размер, TTL - extRcode/version/DO
    $class = $this->class;
    $ttl   = $this->ttl;
    
    if ($this->rdata instanceof OptRecord)
    {
      $class =  $this->rdata->udpSize;
      $ttl   =( $this->rdata->extRcode << 24)
            | (($this->rdata->version & 0xFF) << 16)
            | ( $this->rdata->do ? 1 << 15 : 0);
    }
    
    return NameCodec::encode($this->name)
      . pack('nnNn', $this->type, $class, $ttl, strlen($rdata))
      . $rdata;
  }

  /** @return array{0:self, 1:int} */
  public static function deserialize(string $msg, int $off): array
  {
    [$name, $off] = NameCodec::decode($msg, $off);
    [$type, $class, $ttl, $rdLen] = array_values(unpack('ntype/nclass/Nttl/nrdLen', $msg, $off));
    $off += 10;

    $cls   = RdataRegistry::classFor($type);
    $rdata = $cls::deserialize($msg, $off, $rdLen);

    $record = new self($name, $type, $class, $ttl, $rdata);

    if ($type === 41 && $rdata instanceof OptRecord)
    {
      $rdata->udpSize  = $class;
      $rdata->extRcode =  ($ttl >> 24) & 0xFF;
      $rdata->version  =  ($ttl >> 16) & 0xFF;
      $rdata->do = (bool)(($ttl >> 15) & 1);
    }

    return [$record, $off + $rdLen];
  }

  public function __toString(): string
  {
    return sprintf('%-6s %-32s TTL=%-6d %s',
      DnsMessage::typeName($this->type), $this->name, $this->ttl, (string)$this->rdata);
  }

//****************************************************************
// Json

  public function toJson(): array
  {
    $out = [
      'Name' => NameCodec::toPresentation($this->name),
      'Type' => DnsMessage::typeName($this->type),
      'TTL'  => $this->ttl,
      'Data' => $this->rdata->toJson(),
    ];
    if ($this->rdata instanceof OptRecord) {
      // EDNS: свои поля (RFC 8427, 2.4.2)
      $out['UDPSize'] = $this->rdata->udpSize;
      $out['ExtRcode'] = $this->rdata->extRcode;
      $out['Version'] = $this->rdata->version;
      $out['DO'] = $this->rdata->do;
    }
    return $out;
  }

  public static function fromJson(array $j): self
  {
    foreach (['Name', 'Type', 'Data'] as $k) {
      if (!array_key_exists($k, $j)) {
        throw new RuntimeException("В записи нет поля $k");
      }
    }
    $type = DnsMessage::typeFromName($j['Type']);
    $cls  = RdataRegistry::classFor($type);
    $rdata = $cls::fromJson($j['Data']);

    if ($type === 41 && $rdata instanceof OptRecord) {
      $rdata->udpSize  = (int)($j['UDPSize'  ] ?? 4096);
      $rdata->extRcode = (int)($j['ExtRcode' ] ?? 0);
      $rdata->version  = (int)($j['Version'  ] ?? 0);
      $rdata->do       = (bool)($j['DO'      ] ?? false);
    }

    return new self(
      NameCodec::fromPresentation($j['Name']),
      $type,
      (int)($j['Class'] ?? 1),
      (int)($j['TTL'] ?? 0),
      $rdata,
    );
  }
  
//****************************************************************
}
