<?
/* ============================================================
 * NBNS - NetBIOS Name Service, RFC 1001/1002, UDP 137.
 *
 * Это не DNS, хотя конверт похож, поэтому класс стоит отдельно
 * и не трогает DnsMessage, DnsRecord и RdataRegistry:
 *   - имена 16 байт (15 символов + суффикс) кодируются 32
 *     символами со сдвигом на 'A' (first-level encoding);
 *   - в ответах QDCOUNT=0, вопрос не повторяется;
 *   - типы 0x20 (NB) и 0x21 (NBSTAT) не совпадают с DNS
 *     (0x21 = 33 = SRV), а RDATA у них своя.
 *
 * Умеет: NBSTAT - таблица имён хоста (как nbtstat -A),
 *         NB     - разрешение имени в IPv4 (broadcast или WINS).
 * ============================================================ */

final class NbnsClient
{
  public const NB     = 0x0020;
  public const NBSTAT = 0x0021;

  public const SUFFIX_WORKSTATION = 0x00;
  public const SUFFIX_MESSENGER   = 0x03;
  public const SUFFIX_DC          = 0x1C;
  public const SUFFIX_BROWSER     = 0x1D;
  public const SUFFIX_FILE_SERVER = 0x20;

  private int $id = 0;

  public function __construct(
    public string $server    = '255.255.255.255',  // broadcast или адрес WINS
    public int    $port      = 137,
    public int    $timeout   = 2,
    public bool   $broadcast = true,
  ) {}

  /* ---------- Кодирование имени ---------- */

  /** 16 произвольных байт в 32 символа со сдвигом на 'A' */
  public static function encodeRaw(string $raw16): string
  {
    $out = '';
    for ($i = 0; $i < 16; $i++)
    {
      $byte = ord($raw16[$i]);
      $out .= chr(0x41 + ($byte >> 4)) . chr(0x41 + ($byte & 0x0F));
    }

    return $out;
  }

  /** Имя дополняется пробелами до 15 символов, суффикс - 16-й байт */
  public static function encodeName(string $name, int $suffix = self::SUFFIX_WORKSTATION): string
  {
    return self::encodeRaw(strtoupper(substr(str_pad($name, 15, ' ', STR_PAD_RIGHT), 0, 15)) . chr($suffix & 0xFF));
  }

  /** Обратное преобразование: 32 символа -> 16 байт */
  public static function decodeName(string $encoded): string
  {
    $raw = '';
    for ($i = 0; $i < 16; $i++)
      $raw .= chr(((ord($encoded[$i * 2]) - 0x41) << 4) | (ord($encoded[$i * 2 + 1]) - 0x41));

    return $raw;
  }

  /* ---------- Пакет ---------- */

  //TODO: рассмотреть вариант B - сборку через DnsBuilder вместо ручного pack().
  // Имя в first-level encoding (0x20 + 32 символа + 0x00) - валидное DNS-имя
  // из одной 32-байтовой метки, а флаги запроса в NBNS и DNS нулевые, поэтому
  // байты совпадают байт в байт (проверено). Для этого в DnsBuilder нужна
  // фабрика "голого" сообщения без EDNS и флагов, например DnsBuilder::message();
  // она же пригодится в MulticastTransport::request(). Сейчас оставлено ручное
  // pack(): флаги NBNS (NM_FLAGS) не равны DNS-флагам, и смешивать семантику
  // двух протоколов ради двух строк кода не хочется.
  public static function packet(string $name, int $suffix, int $type, int $id = 0x4242): string
  {
    return pack('n', $id)
      . pack('n', 0x0000)                         // флаги: запрос
      . pack('nnnn', 1, 0, 0, 0)                  // QD=1
      . chr(0x20) . ($name === '*' ? self::encodeRaw('*' . str_repeat("\0", 15)) : self::encodeName($name, $suffix)) . "\0"
      . pack('nn', $type, 1);                     // тип и класс IN
  }

  /* ---------- Запросы ---------- */

  /** Таблица имён хоста (NBSTAT) */
  public function stat(string $ip): array
  {
    $this->id = random_int(1, 0xFFFF);

    $names = [];

    foreach ($this->exchange(self::packet('*', self::SUFFIX_WORKSTATION, self::NBSTAT, $this->id), $ip) as $reply)
      foreach (self::records($reply, $this->id) as $record)
      {
        if ($record['type'] !== self::NBSTAT)
          continue;

        $raw   = $record['rdata'];
        $count = ord($raw[0]);

        for ($i = 0; $i < $count && 1 + ($i + 1) * 18 <= strlen($raw); $i++)
        {
          $offset = 1 + $i * 18;
          $flags  = unpack('n', substr($raw, $offset + 16, 2))[1];

          $names[] = [
            'name'    => rtrim(substr($raw, $offset, 15)),
            'suffix'  => ord($raw[$offset + 15]),
            'group'   => (bool)($flags & 0x8000),
            'address' => $ip,
          ];
        }
      }

    return $names;
  }

  /** Разрешение имени в IPv4 (broadcast или конкретный WINS) */
  public function resolve(string $name, int $suffix = self::SUFFIX_WORKSTATION, ?string $target = null): array
  {
    $this->id = random_int(1, 0xFFFF);

    $addresses = [];

    foreach ($this->exchange(self::packet($name, $suffix, self::NB, $this->id), $target) as $reply)
      foreach (self::records($reply, $this->id) as $record)
        if ($record['type'] === self::NB && strlen($record['rdata']) >= 6)
          $addresses[] = implode('.', unpack('C4', substr($record['rdata'], 2, 4)));

    return array_values(array_unique($addresses));
  }

  /* ---------- Разбор ---------- */

  /**
   * Записи ответа в порядке появления.
   * @return array<int,array{name:string,type:int,ttl:int,rdata:string}>
   */
  public static function records(string $reply, ?int $id = null): array
  {
    $reader = DnsReader::of($reply);
    $header = $reader->unpack('nid/nflags/nqd/nan/nns/nar');

    if ($id !== null && $header['id'] !== $id)
      return [];

    // В ответах NBNS вопросов обычно нет (QDCOUNT=0)
    for ($i = 0; $i < $header['qd']; $i++)
    {
      $reader->readName();
      $reader->unpack('nt/nc');
    }

    $records = [];

    for ($i = 0; $i < $header['an']; $i++)
    {
      $name = $reader->readName();
      $rr   = $reader->unpack('nt/nc/Nttl/nlen');

      $records[] = [
        'name'  => $name,
        'type'  => $rr['t'],
        'ttl'   => $rr['ttl'],
        'rdata' => $reader->readBytes($rr['len']),
      ];
    }

    return $records;
  }

  /* ---------- Сеть ---------- */

  /** @return string[] сырые ответы за окно ожидания */
  private function exchange(string $packet, ?string $target = null): array
  {
    $target ??= $this->server;

    $context = stream_context_create(['socket' => [
      'so_broadcast' => $this->broadcast,
      'so_reuseport' => true,
    ]]);

    $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND, $context);
    if ($socket === false)
      throw new RuntimeException("nbns://{$target}:{$this->port}: не удалось открыть сокет: $errstr ($errno)");

    try
    {
      if (@stream_socket_sendto($socket, $packet, 0, "{$target}:{$this->port}") === false)
        throw new RuntimeException("nbns://{$target}:{$this->port}: не удалось отправить запрос");

      $replies  = [];
      $deadline = microtime(true) + $this->timeout;

      while (($left = $deadline - microtime(true)) > 0.001)
      {
        $read   = [$socket];
        $write  = null;
        $except = null;

        if (@stream_select($read, $write, $except, (int)$left, (int)(($left - floor($left)) * 1000000)) === 0)
          break;

        $data = @stream_socket_recvfrom($socket, 65535, 0, $peer);
        if ($data === false || $data === '')
          continue;

        $replies[] = $data;
      }

      return $replies;
    }
    finally { fclose($socket); }
  }

  /* ------------------------------------------------------------
   * Тесты
   * ------------------------------------------------------------ */

  static function test(TestRunner $t)
  {
    /* ---------- Кодирование имени ---------- */

    $t->eq(self::encodeRaw("\x2A" . str_repeat("\0", 15)), 'CK' . str_repeat('AA', 15), 'NbnsClient: wildcard "*" из 16 байт');
    $t->eq(self::encodeName('A'), 'EB' . str_repeat('CA', 14) . 'AA', 'NbnsClient: имя дополняется пробелами');
    $t->eq(strlen(self::encodeName('HOME-WOWA', self::SUFFIX_FILE_SERVER)), 32, 'NbnsClient: код имени - 32 символа');

    $raw = self::decodeName(self::encodeName('HOME-WOWA', self::SUFFIX_FILE_SERVER));
    $t->eq([rtrim(substr($raw, 0, 15)), ord($raw[15])], ['HOME-WOWA', 0x20], 'NbnsClient: код имени разбирается обратно');

    $t->eq(self::decodeName(self::encodeName(str_repeat('X', 20))), str_repeat('X', 15) . "\0", 'NbnsClient: имя обрезается до 15 символов');

    /* ---------- Пакет запроса ---------- */

    $packet = self::packet('*', self::SUFFIX_WORKSTATION, self::NBSTAT, 0x4242);
    $t->eq(hexs(substr($packet, 0, 12)), '42 42 00 00 00 01 00 00 00 00 00 00', 'NbnsClient: заголовок запроса');
    $t->eq([strlen($packet), ord($packet[12])], [12 + 34 + 4, 0x20], 'NbnsClient: длина и префикс имени');
    $t->eq(hexs(substr($packet, -4)), '00 21 00 01', 'NbnsClient: тип NBSTAT и класс IN');
    $t->eq(substr($packet, 13, 32), 'CK' . str_repeat('AA', 15), 'NbnsClient: wildcard в пакете - нули, а не пробелы');

    /* ---------- Разбор NBSTAT-ответа ---------- */

    $names = [
      ['WOWA-SAMARA'   , 0x20, 0x0004],
      ['WOWA-SAMARA'   , 0x00, 0x0004],
      ['WORKGROUP'     , 0x00, 0x8400],
    ];

    $rdata = chr(count($names));
    foreach ($names as [$name, $suffix, $flags])
      $rdata .= str_pad($name, 15, ' ') . chr($suffix) . pack('n', $flags);
    $rdata .= str_repeat("\0", 46);                    // выравнивание, как у nbtstat

    $response = pack('n', 0x4242) . pack('n', 0x8400) . pack('nnnn', 0, 1, 0, 0)
      . chr(0x20) . self::encodeName('*') . "\0"
      . pack('nnNn', self::NBSTAT, 1, 0, strlen($rdata)) . $rdata;

    $records = self::records($response, 0x4242);
    $t->eq(count($records), 1, 'NbnsClient: в ответе одна запись');
    $t->eq($records[0]['type'], self::NBSTAT, 'NbnsClient: тип NBSTAT');

    $decoded = [];
    $raw     = $records[0]['rdata'];
    for ($i = 0; $i < ord($raw[0]); $i++)
    {
      $offset = 1 + $i * 18;
      $decoded[] = [rtrim(substr($raw, $offset, 15)), ord($raw[$offset + 15]),
        (bool)(unpack('n', substr($raw, $offset + 16, 2))[1] & 0x8000)];
    }

    $t->eq($decoded, [
      ['WOWA-SAMARA', 0x20, false],
      ['WOWA-SAMARA', 0x00, false],
      ['WORKGROUP'  , 0x00, true ],
    ], 'NbnsClient: таблица имён разбирается');

    /* ---------- Чужой ID игнорируется ---------- */

    $t->eq(self::records($response, 0x1111), [], 'NbnsClient: ответ с другим ID отбрасывается');

    /* ---------- Разбор NB-ответа ---------- */

    $nb = pack('n', 0x4242) . pack('n', 0x8400) . pack('nnnn', 0, 1, 0, 0)
      . chr(0x20) . self::encodeName('HOME-WOWA') . "\0"
      . pack('nnNn', self::NB, 1, 0, 6) . pack('n', 0x0000) . inet_pton('10.55.0.2');

    $records = self::records($nb, 0x4242);
    $t->eq(implode('.', unpack('C4', substr($records[0]['rdata'], 2, 4))), '10.55.0.2', 'NbnsClient: адрес из NB-ответа');
  }
}