<?
/* ============================================================
 * DnsReader - контролируемое чтение бинарного DNS-сообщения.
 * Единственная точка доступа к байтам при десериализации:
 * любое чтение за границей буфера бросает исключение.
 * ============================================================ */

final class DnsReader
{
  private  string $buf;
  private  int    $off;
  private  int    $max;
  
  public function __construct(
    string $buf,
    int    $off,
    int    $max,
  )
  {
    $this->buf = $buf;
    $this->off = $off;
    $this->max = $max;
    if ($max > strlen($buf)) throw new LogicException('Limit is more than size');
  }
           
  public static function of(string $buf, int $off = 0, ?int $len = null): self
  {
    if ($off < 0) $off += strlen($buf);
    if ($len === null)
      $max = strlen($buf);
    else
      $max = $len >= 0? $len + $off: strlen($buf) + $len;
    
    return new self($buf, $off, $max);
  }
  
  public function sub(int $len)
  {
    $this->ensure($len);
    $result = static::of($this->buf, $this->off, $len);
    $this->off += $len;
    return $result;
  }

  public function tell(): int    { return $this->off; }

  public function remaining(): int { return $this->max - $this->off; }

  public function eof(): bool    { return $this->off >= $this->max; }

  public function seek(int $off): void
  {
    if ($off < 0 || $off > $this->max)
      throw new RuntimeException("Некорректная позиция чтения: $off");
    
    $this->off = $off;
  }

  /** Проверка, что впереди есть $n байт, иначе - исключение */
  public function ensure(int $n, string $what = 'данных'): void
  {
    if ($n < 0)
      throw new RuntimeException('Отрицательная длина чтения');
    
    if ($this->off + $n > $this->max)
      throw new RuntimeException(sprintf(
        'Не хватает %s: нужно %d, в буфере осталось %d',
        $what, $n, $this->remaining(),
      ));
  }

  public function readBytes(int $n): string
  {
    $this->ensure($n);
    $s = substr($this->buf, $this->off, $n);
    $this->off += $n;
    return $s;
  }
  
  protected static array $unpackLengthCache = [];
  
  public static function unpackLength(string $format): int { return static::$unpackLengthCache[$format] ??= self::unpackCalcLength($format); }
  public static function unpackCalcLength(string $format): int
  { // See https://github.com/php/php-src/blob/master/ext/standard/pack.c
    $result = 0;
    $offset = 0;
    $format = explode('/', $format);
    foreach ($format as $item)
    {
      $size = match($item[0]) {
        'c','C', 'a', 'A', 'Z', 'x'       => 1,
        's', 'S', 'n', 'v'                => 2,
        'l', 'L', 'N', 'V', 'f', 'g', 'G' => 4,
        'q', 'Q', 'J', 'P', 'd', 'e', 'E' => 8,
        'i', 'I'                          => PHP_INT_SIZE,
        '@'                               => -2,
        'X'                               => -1,
        'h', 'H'                          => -3,
        default => throw new RuntimeException('unsupported format '.$item),
      };
      
      // < -- Little endian
      // > -- Big endian
      
      $i = 1;
      if ($i < strlen($item) && ($item[$i] == '<' || $item[$i] == '>'))
        $i++;

      if (($item[$i]?? '')==='*') throw new RuntimeException('unsupported format '.$item);
      
      $repeat = 0;
      for (; $i < strlen($item) && $item[$i] <= '9' && $item[$i] >= '0'; $i++)
        $repeat = $repeat*10 + ord($item[$i]) - ord('0');
        
      if ($size === -3) //Hex
      {
        if ($repeat <= 0) throw new RuntimeException('unsupported format '.$item);
        $size   = ($repeat + 1) >> 1;
        $repeat = 1;
      }
      
      if ($size === -2) //Abs
      {
        $size   = 1;
        $offset = 0;
      }
      
      $offset += $repeat? $size*$repeat: $size;
      $result = max($result, $offset);
    }
    return $result;
  }
  
  public function unpack(string $format, ?int $len = null)
  {
    $len ??= self::unpackLength($format);
    $this->ensure($len);
    $result = unpack($format, $this->buf, $this->off);
    $this->off += $len;
    return $result;
  }

  public function readU8(): int
  {
    $this->ensure(1);
    $result = ord($this->buf[$this->off]);
    $this->off += 1;
    return $result;
  }

  public function readU16(): int { return $this->unpack('n', 2)[1]; }
  public function readU32(): int { return $this->unpack('N', 4)[1]; }

  /**
   * Чтение доменного имени с поддержкой compression pointers.
   * После указателя позиция чтения встаёт сразу за ним (2 байта),
   * само имя дочитывается по месту, на которое указатель ссылается.
   */
  public function readName(): string
  {
    $buf  = $this->buf;
    $max  = $this->max;
    $off  = $this->off;
    $labels = [];
    $end  = null;   // куда встать после имени в исходном потоке
    $jumps  = 0;

    while (true)
    {
      if ($off >= $max)
        throw new RuntimeException('Имя выходит за границы сообщения');
      
      $len = ord($buf[$off]);

      if ($len === 0) // конец имени
      {
        $off++;
        break;
      }

      if (($len & 0xC0) === 0xC0) // compression pointer
      {
        if ($off + 1 >= $max)
          throw new RuntimeException('Обрезанный compression pointer');
        
        if ($end === null)
          $end = $off + 2;        // 2 байта тратит сам указатель
        
        if (++$jumps > 128)
          throw new RuntimeException('Зацикливание compression pointers');
        
        $ptr = (($len & 0x3F) << 8) | ord($buf[$off + 1]);
        if ($ptr >= $off)
          throw new RuntimeException('Указатель указывает вперёд или на себя');
        
        $off = $ptr;
        continue;
      }

      if (($len & 0xC0) !== 0)
        throw new RuntimeException("Некорректная длина метки: $len");
      
      if ($off + 1 + $len > $max)
        throw new RuntimeException('Метка выходит за границы сообщения');
      

      $labels[] = substr($buf, $off + 1, $len);
      $off += 1 + $len;
    }

    $this->off = $end ?? $off;
    return implode('.', $labels);
  }
  
  static function test(TestRunner $t)
  {
    // Test unpack
    
    // 1 байт (C) + 2 байта (n) + 10 байт (a10) + 4 байта (V) = 17 байт
    $t->eq(self::unpackLength('Ctype/nversion/a10name/Vtimestamp'), 17, 'unpack1'); // int(17)

    // Возврат на 2 байта назад: 4 (N) - 2 (X2) + 2 (n) = 4 байта
    $t->eq(self::unpackLength('Nfirst/X2/noverlap'), 4, 'unpack back 4'); // int(4)

    // Возврат на 2 байта назад: 4 (N) - 2 (X2) + 2 (n) = 4 байта
    $t->eq(self::unpackLength('Nfirst/@2/noverlap'), 4, 'unpack 4'); // int(4)

    // Неограниченная длина из-за '*'
    $t->throws(fn() => self::unpackLength('Cflags/a*payload'), 'unpack infinite'); // NULL
  
    /* ---------- DnsReader: базовое чтение ---------- */
    
    $r = DnsReader::of("\x01\x02\x03\x04\x05");
    $t->eq($r->tell(), 0, 'DnsReader: начальная позиция 0');
    $t->eq($r->readU16(), 0x0102, 'DnsReader: readU16 big-endian');
    $t->eq($r->tell(), 2, 'DnsReader: tell продвинулся на 2');
    $t->eq($r->readU8(), 3, 'DnsReader: readU8');
    $t->eq($r->remaining(), 2, 'DnsReader: remaining после чтений');
    $t->eq($r->readBytes(2), "\x04\x05", 'DnsReader: readBytes ровно до конца');
    $t->eq($r->eof(), true, 'DnsReader: eof после чтения всего буфера');
    
    $t->eq(DnsReader::of(hex2bin('00000001'))->readU32(), 1, 'DnsReader: readU32 big-endian');
    $t->eq(DnsReader::of(hex2bin('FFFF'))->readU16(), 65535, 'DnsReader: readU16 верхняя граница');
    
    /* ---------- DnsReader: выход за границы -> исключения ---------- */
    
    $t->throws(fn() => DnsReader::of("\x01")->readU16(), 'DnsReader: readU16 на одном байте -> исключение');
    $t->throws(fn() => DnsReader::of("\x01\x02\x03")->readU32(), 'DnsReader: readU32 на трёх байтах -> исключение');
    $t->throws(fn() => DnsReader::of("abc")->readBytes(4), 'DnsReader: readBytes за границей -> исключение');
    $t->throws(fn() => DnsReader::of("abc")->readBytes(-1), 'DnsReader: отрицательная длина -> исключение');
    $t->throws(fn() => DnsReader::of("abc")->seek(10), 'DnsReader: seek за границу -> исключение');
    $t->throws(fn() => DnsReader::of("abc")->seek(-1), 'DnsReader: seek в минус -> исключение');
    
    /* ---------- DnsReader: имена ---------- */
    
    // Корень: нулевой байт -> пустая строка, позиция 1
    $rr = DnsReader::of("\x00\xAA\xBB");
    $t->eq($rr->readName(), '', 'DnsReader: корень -> пустая строка');
    $t->eq($rr->tell(), 1, 'DnsReader: корень тратит 1 байт');
    
    // Имя + указатель: позиция и содержимое
    $nameBuf = "\x03www\x07example\x03com\x00" . "\xC0\x0C" . "\x00\x01\x00\x01";
    $rn = DnsReader::of($nameBuf);
    $t->eq($rn->readName(), 'www.example.com', 'DnsReader: имя без указателя');
    $t->eq($rn->tell(), 17, 'DnsReader: позиция после литерального имени');
    $t->eq($rn->readName(), 'com', 'DnsReader: имя по указателю C0 0C');
    $t->eq($rn->tell(), 19, 'DnsReader: указатель тратит 2 байта в исходном потоке');
    
    // Указатель, ведущий на имя, разобранное частично: a.example.com -> суффикс example.com
    $mix = "\x01a\x07example\x03com\x00" . "\xC0\x02";
    $rm = DnsReader::of($mix);
    $t->eq($rm->readName(), 'a.example.com', 'DnsReader: полное имя');
    $t->eq($rm->readName(), 'example.com', 'DnsReader: указатель на суффикс посреди имени');
    
    // Ошибки имён
    $t->throws(fn() => DnsReader::of(''             )->readName(), 'DnsReader: readName на пустом буфере -> исключение');
    $t->throws(fn() => DnsReader::of("\xC0"         )->readName(), 'DnsReader: обрезанный указатель -> исключение');
    $t->throws(fn() => DnsReader::of("\xC0\x00"     )->readName(), 'DnsReader: указатель на себя -> исключение');
    $t->throws(fn() => DnsReader::of("\x05abcd\x00" )->readName(), 'DnsReader: метка длиннее буфера -> исключение');
    $t->throws(fn() => DnsReader::of("\x03ab\x00"   )->readName(), 'DnsReader: метка длиннее заявленного -> исключение');
    
    // Замкнутый цикл из двух указателей: C0 02 <-> C0 00
    $t->throws(fn() => DnsReader::of("\xC0\x02\xC0\x00")->readName(), 'DnsReader: цикл из двух указателей -> исключение');
  }
}
