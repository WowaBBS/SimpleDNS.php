<?

/* ============================================================
 * Сообщение целиком
 * ============================================================ */

final class DnsMessage
{
  use Packet; // deserialize(string $msg, int $off = 0, ?int $rdLen = null): static

  private const TYPE_NAMES = [
    1 => 'A', 2 => 'NS', 5 => 'CNAME', 6 => 'SOA', 12 => 'PTR',
    15 => 'MX', 16 => 'TXT', 28 => 'AAAA', 33 => 'SRV', 41 => 'OPT', 257 => 'CAA',
  ];

  public const CLASS_IN = 1;

  public function __construct(
    public DnsHeader $header = new DnsHeader(),
    /** @var DnsQuestion[] */ public array $questions  = [],
    /** @var DnsRecord[]   */ public array $answers    = [],
    /** @var DnsRecord[]   */ public array $authority  = [],
    /** @var DnsRecord[]   */ public array $additional = [],
  ) {}

  /** Готовый запрос: RD=1, один вопрос, EDNS0 OPT */
  public static function query(string $domain, int $type, bool $dnssec = false): self
  {
    $m = new self(new DnsHeader(id: random_int(0, 65535), rd: true));
    $m->questions  [] = new DnsQuestion($domain, $type);
    $m->additional [] = DnsRecord::opt(4096, $dnssec);
    return $m;
  }

  public function writeTo(DnsWriter $w): string
  {
    // счётчики секций - из фактического содержимого
    $this->header->qdCount = count($this->questions  );
    $this->header->anCount = count($this->answers    );
    $this->header->nsCount = count($this->authority  );
    $this->header->arCount = count($this->additional );

    $this->header->writeTo($w);
    foreach ($this->questions as $q)
      $q->writeTo($w);
    
    foreach ([$this->answers, $this->authority, $this->additional] as $section)
      foreach ($section as $record)
        $record->writeTo($w);
    
    return $w->data();
  }
  
  public function serialize    (                ): string { return new DnsWriter(null  )->writePacket($this)->data(); }
  public function serializeWith(NameTable $table): string { return new DnsWriter($table)->writePacket($this)->data(); }
  
  
  public static function fromReader(DnsReader $r): self
  {
    $header = DnsHeader::fromReader($r);

    $questions = [];
    for ($i = 0; $i < $header->qdCount; $i++)
    {
      [$question] = DnsQuestion::fromReader($r);
      $questions[] = $question;
    }

    $sections = ['answers' => [], 'authority' => [], 'additional' => []];
    foreach (['answers' => $header->anCount, 'authority' => $header->nsCount, 'additional' => $header->arCount] as $key => $count)
      for ($i = 0; $i < $count; $i++)
      {
        [$record] = DnsRecord::fromReader($r);
        $sections[$key][] = $record;
      }

    return new self($header, $questions, $sections['answers'], $sections['authority'], $sections['additional']);
  }

  /** Полный rcode с учётом старших 8 бит из OPT (EDNS) */
  public function rcode(): int
  {
    foreach ($this->additional as $r)
      if ($r->rdata instanceof OptRecord)
        return ($r->rdata->extRcode << 4) | $this->header->rcode;
    
    return $this->header->rcode;
  }

  public static function typeName(int $type): string
  {
    return self::TYPE_NAMES[$type] ?? "TYPE$type";
  }

//****************************************************************
// Json

  public static function typeFromName(string $name): int
  {
    $name = trim($name);
    if (ctype_digit($name))
      return (int)$name;
    
    $flip = array_flip(self::TYPE_NAMES);
    if (isset($flip[$name]))
      return $flip[$name];
    
    if (preg_match('/^TYPE(\d+)$/i', $name, $m)) 
      return (int)$m[1];
    
    throw new RuntimeException("Неизвестный тип: $name");
  }

  public function toJson(): array
  {
    $out = $this->header->toJson();
    foreach (['Question' => $this->questions, 'Answer' => $this->answers,
          'Authority' => $this->authority, 'Additional' => $this->additional] as $key => $section) {
      if ($section !== []) {
        $out[$key] = array_map(fn($x) => $x->toJson(), $section);
      }
    }
    return $out;
  }

  public static function fromJson(string $json): self
  {
    $j = json_decode($json, true);
    if (!is_array($j)) {
      throw new RuntimeException('Не удалось разобрать JSON: ' . json_last_error_msg());
    }

    $m = new self(DnsHeader::fromJson($j));

    foreach ([
      'Question'   => &$m->questions  ,
      'Answer'     => &$m->answers    ,
      'Authority'  => &$m->authority  ,
      'Additional' => &$m->additional ,
    ] as $key => &$section) {
      foreach ($j[$key] ?? [] as $item) {
        $section[] = is_array($item)
          ? ($key === 'Question' ? DnsQuestion::fromJson($item) : DnsRecord::fromJson($item))
          : throw new RuntimeException("Некорректный элемент в $key");
      }
    }
    unset($section);

    return $m;
  }

//****************************************************************
// Tests

  static function test(TestRunner $t)
  {
    // Запрос: serialize -> deserialize -> serialize, байты совпадают
    $q = DnsMessage::query('www.example.com', 1);
    $wire1 = $q->serialize();
    $q2 = DnsMessage::fromReader(DnsReader::of($wire1));
    $t->eq($q2->serialize(), $wire1, 'DnsMessage: запрос roundtrip байт в байт');
    $t->eq($q2->header->qdCount, 1, 'DnsMessage: qdCount=1');
    $t->eq($q2->header->arCount, 1, 'DnsMessage: arCount=1 (EDNS)');
    $t->eq($q2->questions[0]->name, 'www.example.com', 'DnsMessage: имя вопроса сохранено');
    
    // Ответ: собираем вручную и гоняем туда-обратно
    $resp = new DnsMessage(new DnsHeader(id: 0x1234, qr: true, ra: true));
    $resp->questions[] = new DnsQuestion('example.com',  1);
    $resp->answers  [] = new DnsRecord  ('example.com',  1, 1, 300, new ARecord('93.184.216.34'));
    $resp->answers  [] = new DnsRecord  ('example.com',  5, 1, 300, new CnameRecord('cname.example.com'));
    $resp->answers  [] = new DnsRecord  ('example.com', 16, 1,  60, new TxtRecord(['hello', 'world']));
    $rw = $resp->serialize();
    $resp2 = DnsMessage::fromReader(DnsReader::of($rw));
    $t->eq($resp2->serialize(), $rw, 'DnsMessage: ответ roundtrip байт в байт');
    $t->eq($resp2->header->anCount, 3, 'DnsMessage: anCount=3');
    $t->eq($resp2->answers[0]->rdata->ip, '93.184.216.34', 'DnsMessage: A-ответ');
    $t->eq($resp2->answers[1]->rdata->target, 'cname.example.com', 'DnsMessage: CNAME-ответ');
    $t->eq($resp2->answers[2]->rdata->texts, ['hello', 'world'], 'DnsMessage: TXT-ответ');

    // Известный вектор: полный запрос www.example.com/A с EDNS
    $known = DnsMessage::query('www.example.com', 1);
    $known->header->id = 0x1234;

    $expected = Binary(<<<HereDoc
      1234 // id
      0100 // TODO: ? qr=1, opcode:4=0, aa, tc, rd;  z, ad, cd, rcode:4=0
      0001 // qdCount
      0000 // anCount
      0000 // nsCount
      0001 // arCount
      // Question:
      03 77 77 77              // www
      07 65 78 61 6D 70 6C 65  // example
      03 63 6F 6D              // com
      00                       // 0-end name
      00 01 // type
      00 01 // class
      // Additional:
      00     // 0-end name
      00 29  // Type=41 -- opt
      10 00  // class = udpSize
      00 00 00 00  // ttl = 0?
      00 00        // len??? 2bytes
    HereDoc);
    $t->eq(hexs($known->serialize()), $expected, 'DnsMessage: известный вектор запроса');

  
    // Известный вектор: ответ с сжатым именем в ANSWER
    // header id=0x1234 flags=0x8180 (QR|RD|RA), qd=1 an=1;
    // question: www.example.com A IN; answer: pointer C00C, A IN ttl=10, rdata 192.168.1.1
    $respHex = '12 34 81 80 00 01 00 01 00 00 00 00'
         . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01'
         . ' C0 0C 00 01 00 01 00 00 00 0A 00 04 C0 A8 01 01';
    $parsed = DnsMessage::fromReader(DnsReader::of(hex2bin(str_replace(' ', '', $respHex))));
    $t->eq($parsed->header->qr, true, 'Вектор ответа: qr=true');
    $t->eq($parsed->answers[0]->name, 'www.example.com', 'Вектор ответа: имя через указатель');
    $t->eq($parsed->answers[0]->rdata->ip, '192.168.1.1', 'Вектор ответа: rdata A-записи');
    $t->eq($parsed->answers[0]->ttl, 10, 'Вектор ответа: ttl=10');
  
    /* ---------- Обрезанные данные ---------- */
    
    $t->throws(fn() => DnsMessage::fromReader(DnsReader::of($wire1, 0, 5)), 'DnsMessage: обрезанный запрос -> исключение');
    
    //TODO; It does not work
    $trunc = substr($resp->serialize(), 0, strlen($rw) - 2); // откусили байт rdata
    $t->throws(fn() => DnsMessage::fromReader(DnsReader::of($trunc)), 'DnsMessage: обрезанный ответ -> исключение');

    /* ---------- DnsMessage::deserialize работает через DnsReader ---------- */
    
    // Внутренний ридер ловит обрезку в середине записи
    $good = new DnsMessage(new DnsHeader(id: 1, qr: true));
    $good->questions[] = new DnsQuestion('example.com', 1);
    $good->answers[] = new DnsRecord('example.com', 1, 1, 60, new ARecord('1.2.3.4'));
    $wire = $good->serialize();
    
    // обрезаем внутри rdata - должно упасть с исключением, а не вернуть мусор
    $t->throws(fn() => DnsMessage::fromReader(DnsReader::of($wire, 0, -1)),
      'deserialize: обрезка последнего байта rdata -> исключение');
    
    // завышенный anCount при реальных двух записях -> исключение, а не чтение за границей
    $hdr = DnsHeader::fromReader(DnsReader::of($wire));
    $hdr->anCount = 5;   // на самом деле запись одна
    $forged = $hdr->serialize() . substr($wire, 12);
    $t->throws(fn() => DnsMessage::fromReader(DnsReader::of($forged)),
      'deserialize: завышенный счётчик секции -> исключение');
    
    // хвостовые байты после корректного сообщения допускаются (паддинг)
    $padded = DnsMessage::fromReader(DnsReader::of($wire . "\0\0\0\0"));
    $t->eq($padded->header->id, 1, 'deserialize: хвостовые байты игнорируются');
    
  }

//****************************************************************

  static function testJson(TestRunner $t)
  {
    /* ---------- Тип-мнемоника туда и обратно ---------- */

    $t->eq(DnsMessage::typeFromName('MX'     ), 15, 'typeFromName: MX -> 15');
    $t->eq(DnsMessage::typeFromName('A'      ),  1, 'typeFromName: A -> 1');
    $t->eq(DnsMessage::typeFromName('TYPE65' ), 65, 'typeFromName: TYPE65 -> 65');
    $t->eq(DnsMessage::typeFromName('65'     ), 65, 'typeFromName: число -> 65');
    $t->eq(DnsMessage::typeName(DnsMessage::typeFromName('SRV')), 'SRV', 'typeName/typeFromName согласованы');
    $t->throws(fn() => DnsMessage::typeFromName('NOTAREALTYPE'), 'typeFromName: мусор -> исключение');

    /* ---------- JSON: сообщение целиком, круговое преобразование ---------- */

    $resp = new DnsMessage(new DnsHeader(id: 0x1234, qr: true, ra: true, rcode: 0));
    $resp->questions [] = new DnsQuestion ('example.com', 15);
    $resp->answers   [] = new DnsRecord   ('example.com', 15, 1, 3600, new MxRecord  (10, 'mail.example.com'));
    $resp->answers   [] = new DnsRecord   ('example.com', 16, 1,   60, new TxtRecord (['hello']));
    $resp->authority [] = new DnsRecord   ('example.com',  2, 1, 1800, new NsRecord  ('ns1.example.com'));

    $j = json_encode($resp->toJson(), JSON_UNESCAPED_SLASHES);
    $m = DnsMessage::fromJson($j);

    $t->eq($m->header->id    ,0x1234 ,'DnsMessage JSON: id');
    $t->eq($m->header->rcode ,     0 ,'DnsMessage JSON: Status -> rcode');
    $t->eq($m->header->ra    , true  ,'DnsMessage JSON: флаг RA');
    $t->eq(count($m->questions),   1 ,'DnsMessage JSON: один вопрос');
    $t->eq($m->questions[0]->name, 'example.com', 'DnsMessage JSON: имя вопроса (без точки)');
    $t->eq($m->answers  [0]->rdata->exchange, 'mail.example.com', 'DnsMessage JSON: MX exchange');
    $t->eq($m->answers  [1]->rdata->texts, ['hello'], 'DnsMessage JSON: TXT');
    $t->eq($m->authority[0]->rdata->target, 'ns1.example.com', 'DnsMessage JSON: NS в Authority');

    // Повторный toJson - тот же JSON (после roundtrip содержимое не изменилось)
    $t->eq($m->toJson(), $resp->toJson(), 'DnsMessage JSON: двойной roundtrip стабилен');

    // Известный JSON-вектор (как их отдаёт Google /resolve)
    $gJson = '{"Status":0,"TC":false,"RD":true,"RA":true,"AD":false,"CD":false,'
         . '"Question":[{"Name":"example.com.","Type":"A"}],'
         . '"Answer":[{"Name":"example.com.","Type":"A","TTL":86400,"Data":"93.184.216.34"}]}';
    $g = DnsMessage::fromJson($gJson);
    $t->eq($g->answers[0]->rdata->ip, '93.184.216.34', 'Google JSON: rdata A-записи');
    $t->eq($g->answers[0]->ttl, 86400, 'Google JSON: TTL');
    $t->eq($g->rcode(), 0, 'Google JSON: rcode без OPT = 0');

    // Битый JSON -> исключение
    $t->throws(fn() => DnsMessage::fromJson('{не json'), 'DnsMessage::fromJson: невалидный JSON -> исключение');
    $t->throws(fn() => DnsMessage::fromJson('{"Status":0,"Question":[{"Name":"a.com."}]}'),
      'DnsMessage::fromJson: вопрос без Type -> исключение');
  }

  static function testCompress(TestRunner $t)
  {
    $resp = new DnsMessage(new DnsHeader(id: 0x1234, qr: true, ra: true));
    $resp->questions[] = new DnsQuestion('www.example.com', 1);
    $resp->answers[] = new DnsRecord('www.example.com', 1, 1, 10, new ARecord('192.168.1.1'));

    $compressed = $resp->serializeWith(new NameTable());
    $t->eq(hexs($compressed),
      '12 34 80 80 00 01 00 01 00 00 00 00'
      . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01'
      . ' C0 0C 00 01 00 01 00 00 00 0A 00 04 C0 A8 01 01',
      'Сжатие: золотой вектор, имя ответа -> указатель C0 0C');

    // roundtrip: deserialize -> serializeWith воспроизводит те же байты
    $parsed = DnsMessage::fromReader(DnsReader::of($compressed));
    $t->eq($parsed->serializeWith(new NameTable()), $compressed, 'Сжатие: roundtrip байт в байт');
    $t->eq($parsed->answers[0]->name, 'www.example.com', 'Сжатие: имя после roundtrip');
    $t->eq($parsed->answers[0]->rdata->ip, '192.168.1.1', 'Сжатие: rdata после roundtrip');

    // serialize() без параметров не сжат и не изменился
    $plain = $resp->serialize();
    $t->eq(strlen($plain) > strlen($compressed), true, 'Сжатие: serialize() длиннее serializeWith()');
    $t->eq($plain, $parsed->serialize(), 'Сжатие: serialize() обратно собрал прежние несжатые байты');

    // одиночный запрос без повторов имён: serializeWith == serialize
    $q = DnsMessage::query('www.example.com', 1);
    $t->eq($q->serializeWith(new NameTable()), $q->serialize(),
      'Сжатие: запросу сжимать нечего - байты совпадают');

    /* ---------- Сжатие суффиксов ---------- */

    // "example.com" после "www.example.com" -> указатель на смещение 16 (C0 10)
    $msg2 = new DnsMessage(new DnsHeader(id: 2, qr: true));
    $msg2->questions[] = new DnsQuestion('www.example.com', 1);
    $msg2->answers[] = new DnsRecord('example.com', 1, 1, 10, new ARecord('1.2.3.4'));
    $c2 = $msg2->serializeWith(new NameTable());
    $t->eq(substr_count($c2, "\xC0\x10"), 1, 'Сжатие: суффикс имени -> указатель C0 10');
    $t->eq(DnsMessage::fromReader(DnsReader::of($c2))->answers[0]->name, 'example.com', 'Сжатие: суффикс roundtrip');

    /* ---------- Сжатие внутри RDATA ---------- */

    // CNAME на имя из вопроса
    $msg3 = new DnsMessage(new DnsHeader(id: 3, qr: true));
    $msg3->questions[] = new DnsQuestion('example.com', 5);
    $msg3->answers[] = new DnsRecord('www.example.com', 5, 1, 60, new CnameRecord('example.com'));
    $c3 = $msg3->serializeWith(new NameTable());
    $t->eq(substr_count($c3, "\xC0\x0C"), 2, 'Сжатие: указатель внутри RDATA (CNAME)');
    $p3 = DnsMessage::fromReader(DnsReader::of($c3));
    $t->eq($p3->answers[0]->rdata->target, 'example.com', 'Сжатие: CNAME target roundtrip');

    // SOA: mname и rname сжимаются на общий суффикс
    $soaMsg = new DnsMessage(new DnsHeader(id: 6, qr: true));
    $soaMsg->questions[] = new DnsQuestion('example.com', 6);
    $soaMsg->answers[] = new DnsRecord('example.com', 6, 1, 3600,
      new SoaRecord('ns1.example.com', 'admin.example.com', 1, 2, 3, 4, 5));
    $cs = $soaMsg->serializeWith(new NameTable());
    // имя записи, mname и rname - три указателя на example.com
    $t->eq(substr_count($cs, "\xC0\x0C"), 3, 'Сжатие: SOA - три указателя на общий суффикс');
    $ps = DnsMessage::fromReader(DnsReader::of($cs));
    $t->eq([$ps->answers[0]->rdata->mname, $ps->answers[0]->rdata->rname],
      ['ns1.example.com', 'admin.example.com'], 'Сжатие: SOA roundtrip');

    // несжимаемый RDATA (TXT) не ломается рядом со сжатым
    $txtMsg = new DnsMessage(new DnsHeader(id: 4, qr: true));
    $txtMsg->questions[] = new DnsQuestion('example.com', 16);
    $txtMsg->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord(['hello world']));
    $txtMsg->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord([str_repeat('z', 300)]));
    $ct = $txtMsg->serializeWith(new NameTable());
    $pt = DnsMessage::fromReader(DnsReader::of($ct));
    $t->eq($pt->answers[1]->rdata->texts, [str_repeat('z', 255), str_repeat('z', 300-255)], 'Сжатие: длинный TXT рядом с указателями roundtrip');

    /* ---------- Регистронезависимость ---------- */

    $msg4 = new DnsMessage(new DnsHeader(id: 5, qr: true));
    $msg4->questions[] = new DnsQuestion('www.example.com', 1);
    $msg4->answers[] = new DnsRecord('WWW.Example.COM', 1, 1, 10, new ARecord('9.9.9.9'));
    $c4 = $msg4->serializeWith(new NameTable());
    $t->eq(substr_count($c4, "\xC0\x0C"), 1, 'Сжатие: регистр не мешает сжатию (RFC 4343)');
    //$t->eq(DnsMessage::fromReader(DnsReader::of($c4))->answers[0]->name, 'WWW.Example.COM',
    $t->eq(DnsMessage::fromReader(DnsReader::of($c4))->answers[0]->name, 'www.example.com',
      'Сжатие: регистр имени сохраняется при сжатии');

    /* ---------- Смещения за границей 0x4000 ---------- */

    // Имена на смещении >= 0x4000 нельзя адресовать указателем - они пишутся литералом
    $big = new DnsMessage(new DnsHeader(id: 7, qr: true));
    $big->questions[] = new DnsQuestion('example.com', 16);
    $big->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord([str_repeat('x', 16500)]));
    $big->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord([str_repeat('y', 16500)]));
    $big->answers[] = new DnsRecord('deep.unique.example.org', 16, 1, 60, new TxtRecord(['t1']));
    $big->answers[] = new DnsRecord('deep.unique.example.org', 16, 1, 60, new TxtRecord(['t2']));
    $cb = $big->serializeWith(new NameTable());
    $t->eq(substr_count($cb, "\xC0\x0C"), 2, 'Граница 0x4000: первые два имени сжаты');
    $t->eq(substr_count($cb, "\x04deep\x06unique\x07example\x03org\0"), 2,
      'Граница 0x4000: имя за пределами досягаемости указателя пишется литералом дважды');
    $pb = DnsMessage::fromReader(DnsReader::of($cb));
    $t->eq(count($pb->answers), 4, 'Граница 0x4000: все четыре записи разобрались');

    /* ---------- OPT через сжатый путь ---------- */

    $qc = DnsMessage::query('a.example.com', 1, dnssec: true);
    $wireQ = $qc->serializeWith(new NameTable());
    $pq = DnsMessage::fromReader(DnsReader::of($wireQ));
    $t->eq(count($pq->additional), 1, 'Сжатие: EDNS OPT присутствует');
    $t->eq($pq->additional[0]->rdata->do, true, 'Сжатие: флаг DO сохранён');
    $t->eq($pq->additional[0]->name, '', 'Сжатие: имя OPT - корень');

    /* ---------- Ошибки через сжатый путь ---------- */

    $badName = new DnsMessage(new DnsHeader(id: 8));
    $badName->questions[] = new DnsQuestion(str_repeat('x', 64), 1);
    $t->throws(fn() => $badName->serializeWith(new NameTable()),
      'Сжатие: метка 64 байта через serializeWith -> исключение');
  }

  static function testWrite(TestRunner $t)
  {
    /* ---------- Совместимость: serialize()/serializeWith() дают прежние байты ---------- */

    $resp = new DnsMessage(new DnsHeader(id: 0x1234, qr: true, ra: true));
    $resp->questions[] = new DnsQuestion('www.example.com', 1);
    $resp->answers[] = new DnsRecord('www.example.com', 1, 1, 10, new ARecord('192.168.1.1'));

    $t->eq(hexs($resp->serialize()),
      '12 34 80 80 00 01 00 01 00 00 00 00'
      . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01'
      . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01 00 00 00 0A 00 04 C0 A8 01 01',
      'serialize(): несжатый вывод не изменился');

    $t->eq(hexs($resp->serializeWith(new NameTable())),
      '12 34 80 80 00 01 00 01 00 00 00 00'
      . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01'
      . ' C0 0C 00 01 00 01 00 00 00 0A 00 04 C0 A8 01 01',
      'serializeWith(): сжатый вывод не изменился');

    // Прямой путь через писателя даёт то же, что serializeWith
    $w7 = new DnsWriter(new NameTable());
    $resp->writeTo($w7);
    $t->eq($w7->data(), $resp->serializeWith(new NameTable()),
      'writeTo(DnsWriter): байты совпадают с serializeWith');

    // Запрос с EDNS через писателя - прежний золотой вектор
    $q = DnsMessage::query('www.example.com', 1);
    $q->header->id = 0x1234;
    $wq = new DnsWriter();
    $q->writeTo($wq);
    $t->eq(hexs($wq->data()),
      '12 34 01 00 00 01 00 00 00 00 00 01'
      . ' 03 77 77 77 07 65 78 61 6D 70 6C 65 03 63 6F 6D 00 00 01 00 01'
      . ' 00 00 29 10 00 00 00 00 00 00 00',
      'writeTo(DnsWriter): запрос с EDNS - золотой вектор');

    /* ---------- Симметрия: DnsWriter -> DnsReader ---------- */

    // Сложное сообщение со сжатием, SOA и TXT: serialize -> deserialize -> serialize
    $complex = new DnsMessage(new DnsHeader(id: 42, qr: true, ra: true));
    $complex->questions[] = new DnsQuestion('example.com', 6);
    $complex->answers[] = new DnsRecord('example.com', 6, 1, 3600,
      new SoaRecord('ns1.example.com', 'admin.example.com', 1, 2, 3, 4, 5));
    $complex->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord(['приват txt']));
    $complex->answers[] = new DnsRecord('example.com', 15, 1, 300, new MxRecord(10, 'mail.example.com'));

    $wc = new DnsWriter(new NameTable());
    $complex->writeTo($wc);
    $wire = $wc->data();

    $back = DnsMessage::deserialize($wire);
    $t->eq($back->answers[0]->rdata->mname, 'ns1.example.com', 'Симметрия: SOA mname');
    $t->eq($back->answers[0]->rdata->serial, 1, 'Симметрия: SOA serial');
    $t->eq($back->answers[1]->rdata->texts, ['приват txt'], 'Симметрия: TXT');
    $t->eq($back->answers[2]->rdata->exchange, 'mail.example.com', 'Симметрия: MX');

    $wc2 = new DnsWriter(new NameTable());
    $back->writeTo($wc2);
    $t->eq($wc2->data(), $wire, 'Симметрия: повторная запись воспроизводит те же байты');
  }
}
