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
    public int  $id  =     0,
    public bool $qr  = false, //Xxxx xxxx ответ (0 = запрос)
    public int  $opcode  = 0, //xXXX Xxxx
    public bool $aa  = false, //xxxx xXxx данное поле имеет смысл только в DNS-ответах от сервера и сообщает о том, является ли ответ авторитетным либо нет. 
    public bool $tc  = false, //xxxx xxXx данный флаг устанавливается в пакете ответе в том случае если сервер не смог поместить всю необходимую информацию в пакет из-за существующих ограничений.
    public bool $rd  = false, //xxxx xxxX этот однобитовый флаг устанавливается в запросе и копируется в ответ. Если он флаг устанавливается в запросе — это значит, что клиент просит сервер не сообщать ему промежуточных ответов, а вернуть только IP-адрес.
    public bool $ra  = false, //Xxxx xxxx отправляется только в ответах, и сообщает о том, что сервер поддерживает рекурсию
    public bool $z   = false, //xXxx xxxx являются зарезервированными и всегда равны нулю.
    public bool $ad  = false, //xxXx xxxx ?
    public bool $cd  = false, //xxxX xxxx ?
    public int  $rcode   = 0, //xxxx XXXX это поле служит для уведомления клиентов о том, успешно ли выполнен запрос или с ошибкой.
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
    $t->eq(hexs($h->serialize()), '12 34 81 03 00 00 00 00 00 00 00 00', 'DnsHeader: точные байты (QR|RD, rcode=3)');
    
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

//****************************************************************
// Json

  public function toJson(): array
  {
    return [
      'ID'   => $this->id,
      'Status' => $this->rcode,
      'TC'   => $this->tc,
      'RD'   => $this->rd,
      'RA'   => $this->ra,
      'AD'   => $this->ad,
      'CD'   => $this->cd,
    ];
  }

  public static function fromJson(array $j): self
  {
    return new self(
      id:  (int)($j['ID'] ?? 0),
      rcode: (int)($j['Status'] ?? throw new RuntimeException('В JSON нет поля Status')),
      tc:  (bool)($j['TC'] ?? false),
      rd:  (bool)($j['RD'] ?? false),
      ra:  (bool)($j['RA'] ?? false),
      ad:  (bool)($j['AD'] ?? false),
      cd:  (bool)($j['CD'] ?? false),
    );
  }

//****************************************************************
}
