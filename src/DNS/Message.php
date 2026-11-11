<?

/* ============================================================
 * Сообщение целиком
 * ============================================================ */

final class DnsMessage
{
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

  public function serialize(): string
  {
    // счётчики секций - из фактического содержимого
    $this->header->qdCount = count($this->questions  );
    $this->header->anCount = count($this->answers    );
    $this->header->nsCount = count($this->authority  );
    $this->header->arCount = count($this->additional );

    $out = $this->header->serialize();
    foreach ($this->questions as $q)
      $out .= $q->serialize();
    
    foreach ([$this->answers, $this->authority, $this->additional] as $section)
      foreach ($section as $record)
        $out .= $record->serialize();
    
    return $out;
  }

  public function serializeWith(NameTable $table): string
  {
    $this->header->qdCount = count($this->questions  );
    $this->header->anCount = count($this->answers    );
    $this->header->nsCount = count($this->authority  );
    $this->header->arCount = count($this->additional );

    $buf = $this->header->serialize();
    foreach ($this->questions as $q) {
      $q->serializeWith($table, $buf);
    }
    foreach ([$this->answers, $this->authority, $this->additional] as $section) {
      foreach ($section as $record) {
        $record->serializeWith($table, $buf);
      }
    }
    return $buf;
  }
  
  public static function deserialize(string $buf): self
  {
    $header = DnsHeader::deserialize($buf);
    $off = 12;

    $questions = [];
    for ($i = 0; $i < $header->qdCount; $i++)
    {
      [$question, $off] = DnsQuestion::deserialize($buf, $off);
      $questions[] = $question;
    }

    $sections = ['answers' => [], 'authority' => [], 'additional' => []];
    foreach (['answers' => $header->anCount, 'authority' => $header->nsCount, 'additional' => $header->arCount] as $key => $count)
      for ($i = 0; $i < $count; $i++)
      {
        [$record, $off] = DnsRecord::deserialize($buf, $off);
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

  static function test(TestRunner $t)
  {
    // Запрос: serialize -> deserialize -> serialize, байты совпадают
    $q = DnsMessage::query('www.example.com', 1);
    $wire1 = $q->serialize();
    $q2 = DnsMessage::deserialize($wire1);
    $t->eq($q2->serialize(), $wire1, 'DnsMessage: запрос roundtrip байт в байт');
    $t->eq($q2->header->qdCount, 1, 'DnsMessage: qdCount=1');
    $t->eq($q2->header->arCount, 1, 'DnsMessage: arCount=1 (EDNS)');
    $t->eq($q2->questions[0]->name, 'www.example.com', 'DnsMessage: имя вопроса сохранено');
    
    // Ответ: собираем вручную и гоняем туда-обратно
    $resp = new DnsMessage(new DnsHeader(id: 0x1234, qr: true, ra: true));
    $resp->questions[] = new DnsQuestion('example.com', 1);
    $resp->answers[] = new DnsRecord('example.com', 1, 1, 300, new ARecord('93.184.216.34'));
    $resp->answers[] = new DnsRecord('example.com', 5, 1, 300, new CnameRecord('cname.example.com'));
    $resp->answers[] = new DnsRecord('example.com', 16, 1, 60, new TxtRecord(['hello', 'world']));
    $rw = $resp->serialize();
    $resp2 = DnsMessage::deserialize($rw);
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
    $parsed = DnsMessage::deserialize(hex2bin(str_replace(' ', '', $respHex)));
    $t->eq($parsed->header->qr, true, 'Вектор ответа: qr=true');
    $t->eq($parsed->answers[0]->name, 'www.example.com', 'Вектор ответа: имя через указатель');
    $t->eq($parsed->answers[0]->rdata->ip, '192.168.1.1', 'Вектор ответа: rdata A-записи');
    $t->eq($parsed->answers[0]->ttl, 10, 'Вектор ответа: ttl=10');
  
    /* ---------- Обрезанные данные ---------- */
    
    $t->throws(fn() => DnsMessage::deserialize(substr($wire1, 0, 5)), 'DnsMessage: обрезанный запрос -> исключение');
    
    //TODO; It does not work
    $trunc = substr($resp->serialize(), 0, strlen($rw) - 2); // откусили байт rdata
    $t->throws(fn() => DnsMessage::deserialize($trunc), 'DnsMessage: обрезанный ответ -> исключение');
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
}
