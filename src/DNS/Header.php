<?
/* ============================================================
 * Заголовок сообщения (12 байт)
 * ============================================================ */

final class DnsHeader
{
  private const RCODES = [
    0 => 'NOERROR', 1 => 'FORMERR', 2 => 'SERVFAIL', 3 => 'NXDOMAIN',
    4 => 'NOTIMP',  5 => 'REFUSED',
  ];

  public function __construct(
    public int  $id = 0,
    public bool $qr = false,    // ответ (0 = запрос)
    public int  $opcode = 0,
    public bool $aa = false,
    public bool $tc = false,
    public bool $rd = false,
    public bool $ra = false,
    public bool $z = false,
    public bool $ad = false,
    public bool $cd = false,
    public int  $rcode = 0,
    public int  $qdCount = 0,
    public int  $anCount = 0,
    public int  $nsCount = 0,
    public int  $arCount = 0,
  ) {}

  public function serialize(): string
  {
    $flags = ($this->qr  ? 1 << 15 : 0)
         | (($this->opcode & 0xF) << 11)
         | ($this->aa ? 1 << 10 : 0)
         | ($this->tc ? 1 << 9  : 0)
         | ($this->rd ? 1 << 8  : 0)
         | ($this->ra ? 1 << 7  : 0)
         | ($this->z  ? 1 << 6  : 0)
         | ($this->ad ? 1 << 5  : 0)
         | ($this->cd ? 1 << 4  : 0)
         | ($this->rcode & 0xF);

    return pack('nnnnnn', $this->id, $flags, $this->qdCount, $this->anCount, $this->nsCount, $this->arCount);
  }

  public static function deserialize(string $buf): self
  {
    if (strlen($buf) < 12)
      throw new RuntimeException('Ответ короче DNS-заголовка');
    
    [$id, $flags, $qd, $an, $ns, $ar] = array_values(unpack('n6', $buf));

    return new self(
      id      : $id,
      qr      : (bool)(($flags >> 15) & 1),
      opcode  :        ($flags >> 11) & 0xF,
      aa      : (bool)(($flags >> 10) & 1),
      tc      : (bool)(($flags >>  9) & 1),
      rd      : (bool)(($flags >>  8) & 1),
      ra      : (bool)(($flags >>  7) & 1),
      z       : (bool)(($flags >>  6) & 1),
      ad      : (bool)(($flags >>  5) & 1),
      cd      : (bool)(($flags >>  4) & 1),
      rcode   : $flags & 0xF,
      qdCount : $qd,
      anCount: $an,
      nsCount: $ns,
      arCount: $ar,
    );
  }

  public static function rcodeName(int $rcode): string
  {
    return self::RCODES[$rcode] ?? "RCODE$rcode";
  }
  
  static function test(TestRunner $t)
  {
    $h = new DnsHeader(id: 0x1234, qr: true, rd: true, rcode: 3);
    $t->eq(hexs($h->serialize()), '12 34 81 83 00 00 00 00 00 00 00 00', 'DnsHeader: точные байты (QR|RD, rcode=3)');
    
    $h2 = DnsHeader::deserialize($h->serialize());
    $t->eq($h2->id    , 0x1234 , 'DnsHeader: roundtrip id');
    $t->eq($h2->qr    , true   , 'DnsHeader: roundtrip qr');
    $t->eq($h2->rd    , true   , 'DnsHeader: roundtrip rd');
    $t->eq($h2->rcode ,      3 , 'DnsHeader: roundtrip rcode');
    $t->eq($h2->ra    , false  , 'DnsHeader: ra по умолчанию false');
    
    $t->eq(DnsHeader::rcodeName(0), 'NOERROR', 'DnsHeader: rcodeName(0)');
    $t->eq(DnsHeader::rcodeName(3), 'NXDOMAIN', 'DnsHeader: rcodeName(3)');
    
    $t->throws(fn() => DnsHeader::deserialize('1234'), 'DnsHeader: буфер < 12 байт -> исключение');
  }
}
