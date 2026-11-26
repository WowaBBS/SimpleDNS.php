<?
/* ============================================================
 * DnsBuilder - короткая сборка сообщения.
 * Хранит дефолты (TTL, класс) и собирает сообщение одним
 * выражением: заготовка + секции + build().
 * ============================================================ */

final class DnsBuilder
{
  private DnsMessage $message;
  private int $ttl   = 0;
  private int $class = DnsMessage::CLASS_IN;

  private function __construct(DnsHeader $header)
  {
    $this->message = new DnsMessage($header);
  }

  /* ---------- Заготовки ---------- */

  public static function request(?int $id = null, bool $dnssec = false): self
  {
    $builder = new self(new DnsHeader(id: $id ?? random_int(0, 65535), rd: true));

    $builder->message->additional[] = DnsRecord::opt(4096, $dnssec);

    return $builder;
  }

  public static function response(int $id = 0, bool $qr = true, bool $ra = true): self
  {
    return new self(new DnsHeader(id: $id, qr: $qr, ra: $ra));
  }

  /* ---------- Настройки по умолчанию ---------- */

  public function ttl  (int $ttl  ): self { $this->ttl   = $ttl  ; return $this; }
  public function class(int $class): self { $this->class = $class; return $this; }
  public function id   (int $id   ): self { $this->message->header->id = $id; return $this; }

  /* ---------- Секции ---------- */

  public function q(string $name, int $type = 1): self
  {
    $this->message->questions[] = new DnsQuestion($name, $type, $this->class);
    return $this;
  }

  public function answer   (string $name, DnsRdata $rdata, ?int $ttl = null): self { return $this->add($this->message->answers   , $name, $rdata, $ttl); }
  public function authority(string $name, DnsRdata $rdata, ?int $ttl = null): self { return $this->add($this->message->authority , $name, $rdata, $ttl); }
  public function additional(string $name, DnsRdata $rdata, ?int $ttl = null): self { return $this->add($this->message->additional, $name, $rdata, $ttl); }

  /* ---------- Сахар по типам записей ---------- */

  public function a    (string $name, string $ip, ?int $ttl = null): self
  {
    return $this->answer($name, new ARecord($ip), $ttl);
  }

  public function aaaa (string $name, string $ip, ?int $ttl = null): self
  {
    return $this->answer($name, new AaaaRecord($ip), $ttl);
  }

  public function cname(string $name, string $target, ?int $ttl = null): self
  {
    return $this->answer($name, new CnameRecord($target), $ttl);
  }

  public function ns   (string $name, string $target, ?int $ttl = null): self
  {
    return $this->answer($name, new NsRecord($target), $ttl);
  }

  public function ptr  (string $name, string $target, ?int $ttl = null): self
  {
    return $this->answer($name, new PtrRecord($target), $ttl);
  }

  public function mx   (string $name, int $pref, string $exchange, ?int $ttl = null): self
  {
    return $this->answer($name, new MxRecord($pref, $exchange), $ttl);
  }

  public function txt  (string $name, string|array $text, ?int $ttl = null): self
  {
    return $this->answer($name, new TxtRecord((array)$text), $ttl);
  }

  public function soa  (string $name, string $mname, string $rname, int $serial, int $refresh, int $retry, int $expire, int $minimum, ?int $ttl = null): self
  {
    return $this->answer($name, new SoaRecord($mname, $rname, $serial, $refresh, $retry, $expire, $minimum), $ttl);
  }

  public function srv  (string $name, int $priority, int $weight, int $port, string $target, ?int $ttl = null): self
  {
    return $this->answer($name, new SrvRecord($priority, $weight, $port, $target), $ttl);
  }

  public function caa  (string $name, int $flags, string $tag, string $value, ?int $ttl = null): self
  {
    return $this->answer($name, new CaaRecord($flags, $tag, $value), $ttl);
  }

  /** Запись неизвестного типа: код типа задаётся явно */
  public function raw(string $name, int $type, string $bytes, ?int $ttl = null): self
  {
    $this->message->answers[] = new DnsRecord($name, new UnknownRdata($bytes), $ttl ?? $this->ttl, $this->class, $type);
    return $this;
  }

  /** OPT-запись в ADDITIONAL (EDNS) */
  public function opt(int $udpSize = 4096, bool $dnssec = false): self
  {
    $this->message->additional[] = DnsRecord::opt($udpSize, $dnssec);
    return $this;
  }

  public function build(): DnsMessage { return $this->message; }

  private function add(DnsRecordList $section, string $name, DnsRdata $rdata, ?int $ttl): self
  {
    $section[] = new DnsRecord($name, $rdata, $ttl ?? $this->ttl, $this->class);
    return $this;
  }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    /* ---------- Одно выражение вместо четырёх стейтментов ---------- */

    $m = DnsBuilder::response(id: 0x1234)
      ->q ('example.com')
      ->a ('example.com', '1.2.3.4', 300)
      ->mx('example.com', 10, 'mail.example.com', 3600)
      ->build();

    $t->eq($m->header->id, 0x1234, 'DnsBuilder: id из заготовки');
    $t->eq([$m->header->qr, $m->header->ra, $m->header->rd], [true, true, false], 'DnsBuilder: флаги ответа');
    $t->eq(count($m->questions), 1, 'DnsBuilder: вопрос добавлен');
    $t->eq(count($m->answers), 2, 'DnsBuilder: две записи');
    $t->eq($m->answers[0]->type, 1, 'DnsBuilder: тип выведен из ARecord');
    $t->eq($m->answers[1]->type, 15, 'DnsBuilder: тип выведен из MxRecord');
    $t->eq($m->answers[0]->ttl, 300, 'DnsBuilder: TTL явный');
    $t->eq($m->answers[0]->rdata->ip, '1.2.3.4', 'DnsBuilder: rdata на месте');

    /* ---------- Дефолт TTL ---------- */

    $m2 = DnsBuilder::response()
      ->ttl(120)
      ->a  ('example.com', '1.1.1.1')
      ->a  ('example.com', '8.8.8.8', 30)
      ->build();
    $t->eq($m2->answers->ttls(), [120, 30], 'DnsBuilder: ttl() задаёт дефолт для следующих записей');

    /* ---------- Заготовка запроса ---------- */

    $req = DnsBuilder::request(id: 5, dnssec: true)->q('example.com')->build();
    $t->eq($req->header->rd, true, 'DnsBuilder: request ставит RD');
    $t->eq($req->additional->opt()?->do, true, 'DnsBuilder: request с DNSSEC ставит DO');

    /* ---------- Прочие секции и явный тип ---------- */

    $m3 = DnsBuilder::response()
      ->authority('example.com', new NsRecord('ns1.example.com'), 1800)
      ->raw      ('example.com', 999, "\xDE\xAD")
      ->opt      (1232)
      ->build();

    $t->eq($m3->authority[0]->type, 2, 'DnsBuilder: authority');
    $t->eq($m3->answers[0]->type, 999, 'DnsBuilder: raw-запись с явным типом');
    $t->eq($m3->additional->opt()->udpSize, 1232, 'DnsBuilder: opt в additional');

    /* ---------- Цепочка и результат ---------- */

    $b = DnsBuilder::response();
    $t->eq($b->ttl(5), $b, 'DnsBuilder: методы возвращают тот же builder');

    $m4 = DnsBuilder::response()->a('a.example.com', '1.2.3.4')->txt('b.example.com', 'x')->build();
    $t->eq($m4->answers->find('b.example.com')->type, 16, 'DnsBuilder: результат - обычное сообщение');

    /* ---------- Собранное сообщение пишется и читается как обычно ---------- */

    $wire = $m->serialize();
    $t->eq(DnsMessage::fromReader(DnsReader::of($wire))->serialize(), $wire, 'DnsBuilder: сообщение проходит wire roundtrip');
    $t->eq(DnsMessage::fromJson($m->toJsonString())->serialize(), $wire, 'DnsBuilder: сообщение проходит JSON roundtrip');
  }
}
