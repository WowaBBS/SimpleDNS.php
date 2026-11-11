<?
/* ============================================================
 * Кодирование имён (RFC 1035, 3.1 и 4.1.4)
 * Сериализация - без сжатия (это допустимо по RFC),
 * десериализация - с полной поддержкой compression pointers.
 * ============================================================ */

final class NameCodec
{
  public static function encode(string $name): string
  {
    $out = '';
    if (rtrim($name, '.')!=='')
      foreach (explode('.', rtrim($name, '.')) as $label)
      {
        $len = strlen($label);
        if ($len === 0 || $len > 63)
          throw new InvalidArgumentException("Некорректная метка в имени: '$label' in '$name'");
        
        $out .= chr($len) . $label;
      }
    return $out . "\0";
  }

  /** @return array{0:string, 1:int} [имя, смещение сразу после имени в буфере] */
  public static function decode(string $msg, int $off): array
  {
    $labels = [];
    $end = null;
    $jumps = 0;
    $max = strlen($msg);

    while (true)
    {
      if ($off >= $max)
        throw new RuntimeException('Имя выходит за границы сообщения');
      
      $len = ord($msg[$off]);

      if ($len === 0)
      { // конец имени
        $off++;
        break;
      }

      if (($len & 0xC0) === 0xC0)
      { // compression pointer
        if ($off + 1 >= $max)
          throw new RuntimeException('Обрезанный compression pointer');
        
        if ($end === null)
          $end = $off + 2;      // где кончилось бы имя без указателя
        
        if (++$jumps > 128)
          throw new RuntimeException('Зацикливание compression pointers');
        
        $off = (($len & 0x3F) << 8) | ord($msg[$off + 1]);
        continue;
      }

      if (($len & 0xC0) !== 0)
        throw new RuntimeException("Некорректная длина метки: $len");

      $labels[] = substr($msg, $off + 1, $len);
      $off += 1 + $len;
    }

    return [implode('.', $labels), $end ?? $off];
  }
  
  static function test(TestRunner $t)
  {
    // Круговой преобразование имени
    foreach (['example.com', 'www.example.com', 'a.b.c.d.e.f', 'xn--80ak6aa92e.ru'] as $name)
    {
      [$decoded] = NameCodec::decode(NameCodec::encode($name), 0);
      $t->eq($decoded, $name, "NameCodec roundtrip: $name");
    }
    
    // Корень: "." кодируется одним нулевым байтом и декодируется в пустую строку
    $t->eq(NameCodec::encode('.'), "\0", 'NameCodec: корень -> 1 байт');
    $t->eq(NameCodec::decode("\0", 0)[0], '', 'NameCodec: корень -> пустая строка');
    
    // Точный байтовый вывод: a.b -> 01 61 01 62 00
    $t->eq(hexs(NameCodec::encode('a.b')), '01 61 01 62 00', 'NameCodec: точные байты');
    
    // Ошибки: метка длиннее 63 байт, пустая метка (двойная точка)
    $t->throws(fn() => NameCodec::encode(str_repeat('x', 64)), 'NameCodec: метка 64 байта -> исключение');
    $t->throws(fn() => NameCodec::encode('a..b'), 'NameCodec: пустая метка -> исключение');
    
    // Декодирование compression pointer: имя в начале, указатель позже
    //      4      +8=12      +4=16  +1=17   +8=25
    $buf = "\x03www\x07example\x03com\x00" . str_repeat("\0", 8) . "\xC0\x00\x00\x01\x00\x01";
    $t->eq(NameCodec::decode($buf, 25)[0], 'www.example.com', 'NameCodec: указатель на начало буфера');
    
    // Зацикленный указатель -> исключение (защита от бесконечного цикла)
    $t->throws(fn() => NameCodec::decode("\xC0\x00", 0), 'NameCodec: зацикленный указатель -> исключение');
    
    // Обрезанный указатель -> исключение
    $t->throws(fn() => NameCodec::decode("\xC0", 0), 'NameCodec: обрезанный указатель -> исключение');
  }

//****************************************************************
// Json

  // В JSON имена пишутся с точкой на конце (FQDN), у нас внутри - без неё.
  
  public static function toPresentation(string $name): string
  {
    return $name === '' ? '.' : rtrim($name, '.') . '.';
  }

  public static function fromPresentation(string $name): string
  {
    //TODO: Check required point at the end?
    if ($name === '.' || $name === '') return '';
    
    return rtrim($name, '.');
  }

//****************************************************************
// TestJson

  static function testJson(TestRunner $t)
  {
    /* ---------- JSON: имена в презентационном формате ---------- */
  
    $t->eq(NameCodec::toPresentation('example.com'), 'example.com.', 'NameCodec: toPresentation добавляет точку');
    $t->eq(NameCodec::toPresentation(''), '.', 'NameCodec: toPresentation корня');
    $t->eq(NameCodec::fromPresentation('example.com.'), 'example.com', 'NameCodec: fromPresentation убирает точку');
    $t->eq(NameCodec::fromPresentation('.'), '', 'NameCodec: fromPresentation корня');
  }
//****************************************************************
}
